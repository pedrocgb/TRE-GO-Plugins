<?php

/**
 * Orchestrates the two runtime behaviors of the ticket dispatch module:
 * - applyInitialRuleOnCreation(): called from the Ticket pre_item_add hook
 *   (before prepareInputForAdd runs), bypasses GLPI's normal RuleTicket
 *   ONADD engine for the new ticket and evaluates only the single
 *   configured "Regra de Atendimento Inicial" rule against it instead.
 * - dispatch(): called from the POST endpoint, replays the ticket's full,
 *   normal rule set (all active ONADD rules) and applies only the mapped,
 *   supported mutations through one transactional Ticket::update().
 */
class PluginTregopluginsTicketDispatchService
{
    public const AUDIT_TABLE = 'glpi_plugin_tregoplugins_ticketdispatchlogs';

    public static function applyInitialRuleOnCreation(CommonDBTM $item): void
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

        $rule = new RuleTicket();
        $rule_id = PluginTregopluginsTicketDispatchConfig::getInitialRuleId();
        if (!$rule->getRuleWithCriteriasAndActions($rule_id, true, true)) {
            return;
        }

        $input = self::buildInitialRuleInput($item->input);
        $output = $input;
        $params = ['recursive' => true];
        $options = ['condition' => RuleTicket::ONADD];

        $rule->process($input, $output, $params, $options);
        $output = Toolbox::stripslashes_deep($output);

        $replay = new PluginTregopluginsTicketDispatchRuleReplay();
        $mapped = $replay->mapResult($input, $output);

        foreach ($mapped as $key => $value) {
            $item->input[$key] = $value;
        }

        // Forcing an actor via the initial rule would otherwise make GLPI
        // auto-bump a "new" ticket to "assigned" (see
        // CommonITILObject::updateActors()). _do_not_compute_status blocks
        // that so the configured status wins.
        $item->input['status'] = PluginTregopluginsTicketDispatchConfig::getCreationStatus();
        $item->input['_do_not_compute_status'] = true;

        // Bypasses GLPI's normal RuleTicket ONADD engine for this add: only
        // the single rule evaluated above is allowed to run at creation
        // time, the ticket's full rule set is applied later via dispatch().
        $item->input['_skip_rules'] = true;
    }

    /**
     * Enriches the raw pre_item_add input with the same, publicly derivable
     * context Ticket::prepareInputForAdd() feeds its own rule engine
     * (category code, requester location/default group/profile), so the
     * initial rule's criteria can match on them. Contract-based criteria are
     * out of scope here.
     *
     * @param array<string, mixed> $raw_input
     *
     * @return array<string, mixed>
     */
    private static function buildInitialRuleInput(array $raw_input): array
    {
        $input = $raw_input;
        $input['entities_id'] = (int) ($raw_input['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

        if (!empty($input['itilcategories_id'])) {
            $category = ITILCategory::getById((int) $input['itilcategories_id']);
            if ($category !== false) {
                $input['itilcategories_id_code'] = $category->fields['code'] ?? '';
            }
        }

        $requester_id = $input['_users_id_requester'] ?? null;
        if (is_array($requester_id)) {
            $requester_id = reset($requester_id);
        }

        if (!empty($requester_id)) {
            $user = new User();
            if ($user->getFromDB((int) $requester_id)) {
                $input['_locations_id_of_requester'] = $user->fields['locations_id'] ?? 0;
                $input['users_default_groups'] = $user->fields['groups_id'] ?? 0;
                $input['profiles_id'] = $user->fields['profiles_id'] ?? 0;
            }
        }

        return $input;
    }

    public static function hasBeenDispatched(int $tickets_id): bool
    {
        global $DB;

        $result = $DB->request([
            'COUNT'  => 'cpt',
            'FROM'   => self::AUDIT_TABLE,
            'WHERE'  => ['tickets_id' => $tickets_id],
        ])->current();

        return (int) ($result['cpt'] ?? 0) > 0;
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

            $updated = $fresh->update($mapped);
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
                `tickets_id`        int {$default_key_sign} NOT NULL DEFAULT 0,
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
}
