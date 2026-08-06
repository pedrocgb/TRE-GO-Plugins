<?php

/**
 * Orchestrates the two runtime behaviors of the ticket dispatch module:
 * - normalizeGroupOnCreation(): called from the Ticket post_prepareadd
 *   hook, forces every newly created ticket's technician group to the
 *   configured default without touching any other rule result.
 * - dispatch(): called from the POST endpoint, replays creation-time rules
 *   without that suppression and applies only the mapped, supported
 *   mutations through one transactional Ticket::update().
 */
class PluginTregopluginsTicketDispatchService
{
    public const AUDIT_TABLE = 'glpi_plugin_tregoplugins_ticketdispatchlogs';

    /**
     * Set for the duration of dispatch()'s Ticket::update() call only.
     * GLPI's updateActors() doesn't propagate arbitrary '_xxx' input keys
     * down into the Group_Ticket rows it creates, so a plain
     * '_dispatch_by_tregoplugins' input flag never reaches
     * PluginTregopluginsTicketAutomation::restartOlaTtoForGroupAssignment().
     * This same-request static flag is what actually lets that hook tell a
     * dispatch/escalation group change apart from an ordinary technician
     * assignment that happens to touch the group actor too.
     */
    private static bool $dispatching = false;

    public static function isDispatching(): bool
    {
        return self::$dispatching;
    }

    public static function normalizeGroupOnCreation(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket) {
            return;
        }

        if (!PluginTregopluginsTicketDispatchConfig::isRunnable()) {
            return;
        }

        if (!is_array($item->input)) {
            return;
        }

        $default_group_id = PluginTregopluginsTicketDispatchConfig::getDefaultGroupId();

        unset($item->input['_groups_id_assign'], $item->input['_additional_groups_assigns']);
        $item->input['_groups_id_assign'] = $default_group_id;

        if (
            isset($item->input['_itil_assign']['_type'])
            && $item->input['_itil_assign']['_type'] === 'group'
        ) {
            $item->input['_itil_assign']['groups_id'] = $default_group_id;
        }

        // RuleTicket ONADD already picked an OLA for the ticket's eventual
        // real group before this hook runs. While the ticket sits in the
        // default group, its OLA TTO clock must run against the default
        // group's own OLA instead, or the due date/progress bar reflect a
        // group the ticket isn't actually in yet. Dispatching later replays
        // the rules and restores the real OLA (see dispatch()/mapResult()).
        $default_ola_id = PluginTregopluginsTicketDispatchConfig::getDefaultOlaId();
        if ($default_ola_id > 0) {
            $item->input['olas_id_tto'] = $default_ola_id;
        }

        // Forcing a technician group would otherwise make GLPI auto-bump a
        // "new" ticket to "assigned" (see CommonITILObject::updateActors()).
        // _do_not_compute_status blocks that so the configured status wins.
        $item->input['status'] = PluginTregopluginsTicketDispatchConfig::getCreationStatus();
        $item->input['_do_not_compute_status'] = true;

        // GLPI core stamps takeintoaccountdate the moment ANY assign-type
        // actor is attached, group included (see
        // CommonITILObject::updateActors()). Left alone, that fires right
        // here for this synthetic default-dispatch-group placeholder, and
        // PluginTregopluginsTicketAutomation::markOlaTtoAssigned()'s
        // "already set, don't overwrite" guard then permanently locks
        // takeintoaccountdate to ticket-creation time -- a real technician
        // assigned later never gets to correct it, and the OLA TTO progress
        // bar reads as if the clock stopped at t=0. _do_not_compute_takeintoaccount
        // blocks core's stamp here so markOlaTtoAssigned() (which fires on
        // the real actor's own add) is the one that sets it.
        $item->input['_do_not_compute_takeintoaccount'] = true;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public static function dispatch(Ticket $ticket, int $users_id): array
    {
        global $DB;

        $eligibility = PluginTregopluginsTicketDispatchEligibility::evaluate($ticket);
        if (!$eligibility['allowed']) {
            return ['success' => false, 'message' => $eligibility['reason_label']];
        }

        $replay = new PluginTregopluginsTicketDispatchRuleReplay();
        $full = $replay->evaluateFull($ticket);
        $mapped = $replay->mapResult($full['input'], $full['output']);

        if (count($mapped) === 0) {
            return ['success' => false, 'message' => __('Nenhuma alteração aplicável foi encontrada.', 'tregoplugins')];
        }

        $mapped['id'] = $ticket->getID();
        $mapped['_skip_rules'] = true;
        $mapped['_dispatch_by_tregoplugins'] = true;

        // Adding/replacing an assign-type actor would otherwise make GLPI
        // auto-bump the ticket's status (see CommonITILObject::updateActors()),
        // overriding whatever status the ticket already had (e.g. the
        // configured creation status). _do_not_compute_status blocks that;
        // an explicit 'status' action from the replayed rule (if any) is
        // still applied normally since it's a plain field in $mapped.
        $mapped['_do_not_compute_status'] = true;

        $DB->beginTransaction();

        try {
            $fresh = new Ticket();
            if (!$fresh->getFromDB($ticket->getID())) {
                throw new RuntimeException('Ticket not found');
            }

            $recheck = PluginTregopluginsTicketDispatchEligibility::evaluate($fresh);
            if (!$recheck['allowed']) {
                $DB->rollback();
                return ['success' => false, 'message' => $recheck['reason_label']];
            }

            // Ticket::update()'s actor handling is additive/updating only; it
            // never removes an existing actor absent from the new input. The
            // "assign" (replace) semantics of _groups_id_assign therefore has
            // to be applied explicitly here: drop the assign-type group
            // actors that are not part of the freshly calculated set before
            // update() adds the new one(s).
            if (array_key_exists('_groups_id_assign', $mapped) || array_key_exists('_additional_groups_assigns', $mapped)) {
                self::removeStaleAssignGroups($fresh, $recheck['calculated_group_ids']);
            }

            self::$dispatching = true;
            try {
                $updated = $fresh->update($mapped);
            } finally {
                self::$dispatching = false;
            }
            if (!$updated) {
                throw new RuntimeException('Ticket update failed');
            }

            self::writeAuditLog(
                $fresh->getID(),
                $users_id,
                $eligibility['current_group_ids'],
                $eligibility['calculated_group_ids'],
                $eligibility['matched_rule_ids']
            );

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollback();
            Toolbox::logInFile('tregoplugins', 'Ticket dispatch failed for ticket ' . $ticket->getID() . ': ' . $e->getMessage());
            return ['success' => false, 'message' => __('Não foi possível despachar o chamado. Consulte os logs do sistema.', 'tregoplugins')];
        }

        return ['success' => true, 'message' => __('Chamado despachado para a unidade responsável.', 'tregoplugins')];
    }

    /**
     * @param int[] $keep_group_ids
     */
    private static function removeStaleAssignGroups(Ticket $ticket, array $keep_group_ids): void
    {
        foreach ($ticket->getGroups(CommonITILActor::ASSIGN) as $row) {
            $groups_id = (int) ($row['groups_id'] ?? 0);
            if (in_array($groups_id, $keep_group_ids, true)) {
                continue;
            }

            $link = new Group_Ticket();
            $link->delete(['id' => (int) $row['id']], true);
        }
    }

    /**
     * @param int[] $source_group_ids
     * @param int[] $target_group_ids
     * @param int[] $matched_rule_ids
     */
    private static function writeAuditLog(
        int $tickets_id,
        int $users_id,
        array $source_group_ids,
        array $target_group_ids,
        array $matched_rule_ids
    ): void {
        global $DB;

        self::migrateAuditSchema();

        $DB->insert(self::AUDIT_TABLE, [
            'tickets_id'        => $tickets_id,
            'users_id'          => $users_id,
            'source_groups_id'  => implode(',', $source_group_ids),
            'target_groups_id'  => implode(',', $target_group_ids),
            'matched_rules_id'  => implode(',', $matched_rule_ids),
            'date_creation'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::AUDIT_TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::AUDIT_TABLE . "` (
                `id`                int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `tickets_id`        bigint unsigned NOT NULL DEFAULT 0,
                `users_id`          int {$default_key_sign} NOT NULL DEFAULT 0,
                `source_groups_id`  varchar(255) NOT NULL DEFAULT '',
                `target_groups_id`  varchar(255) NOT NULL DEFAULT '',
                `matched_rules_id`  varchar(255) NOT NULL DEFAULT '',
                `date_creation`     timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::AUDIT_TABLE);
        }

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        if ($DB->tableExists(self::AUDIT_TABLE)) {
            $DB->doQueryOrDie("DROP TABLE IF EXISTS `" . self::AUDIT_TABLE . "`", 'Drop ' . self::AUDIT_TABLE);
        }

        return true;
    }

    /**
     * Same self-heal as PluginTregopluginsTaskChecklist::migrateSchema():
     * a ticket id past this column's range makes every audit insert fail
     * silently at the DB layer (logged to SQL log, no PHP exception), so an
     * existing install needs to widen it lazily rather than only on update.
     */
    private static function migrateAuditSchema(): void
    {
        global $DB;

        foreach ($DB->listFields(self::AUDIT_TABLE) as $name => $field) {
            if ($name !== 'tickets_id') {
                continue;
            }

            $type = strtolower((string) ($field['Type'] ?? ''));
            if (strpos($type, 'bigint') !== false && strpos($type, 'unsigned') !== false) {
                return;
            }
            break;
        }

        $migration = new Migration(PLUGIN_TREGOPLUGINS_VERSION);
        $migration->changeField(self::AUDIT_TABLE, 'tickets_id', 'tickets_id', 'bigint unsigned NOT NULL DEFAULT 0');
        $migration->executeMigration();
    }
}
