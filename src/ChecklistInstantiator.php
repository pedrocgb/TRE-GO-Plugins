<?php

/**
 * Materializes a PluginTregopluginsChecklistTemplate onto a just-created
 * TicketTask: resolves exactly one binding, then copies the template and
 * its active items into a PluginTregopluginsTaskChecklist snapshot.
 * Idempotent by construction (unique index on tickettasks_id): a duplicate
 * hook firing, a retry, or a race with another request all converge on the
 * same single instance.
 */
class PluginTregopluginsChecklistInstantiator
{
    /**
     * @return int|null the taskchecklists_id of the (possibly pre-existing)
     *         instance, or null if nothing was materialized
     */
    public static function materialize(TicketTask $task): ?int
    {
        global $DB;

        $tickettasks_id = (int) $task->getID();
        if ($tickettasks_id <= 0) {
            return null;
        }

        $existing = PluginTregopluginsTaskChecklist::getForTask($tickettasks_id);
        if ($existing !== null) {
            return (int) $existing->getID();
        }

        $tasktemplates_id = (int) ($task->fields['tasktemplates_id'] ?? 0);
        if ($tasktemplates_id <= 0) {
            return null;
        }

        $tickets_id = (int) ($task->fields['tickets_id'] ?? 0);
        $ticket = new Ticket();
        if ($tickets_id <= 0 || !$ticket->getFromDB($tickets_id)) {
            return null;
        }

        $binding = PluginTregopluginsChecklistBindingResolver::resolve($tasktemplates_id, $ticket);
        if ($binding === null) {
            return null;
        }

        $template = new PluginTregopluginsChecklistTemplate();
        if (!$template->getFromDB($binding['checklisttemplates_id'])) {
            return null;
        }

        $items = $template->getActiveItems();
        if (empty($items)) {
            return null;
        }

        PluginTregopluginsTaskChecklist::migrateSchema();

        try {
            $DB->beginTransaction();

            // Revalidate under the transaction: another request may have
            // materialized this task between the check above and here.
            $existing = PluginTregopluginsTaskChecklist::getForTask($tickettasks_id);
            if ($existing !== null) {
                $DB->commit();
                return (int) $existing->getID();
            }

            $DB->insert(PluginTregopluginsTaskChecklist::getTable(), [
                'tickettasks_id'               => $tickettasks_id,
                'tickets_id'                   => $tickets_id,
                'source_checklisttemplates_id' => (int) $template->getID(),
                'source_version'                => (int) $template->fields['version'],
                'name_snapshot'                 => $template->fields['name'],
                'completion_policy_snapshot'    => $template->fields['completion_policy'],
                'created_by'                    => (int) ($task->fields['users_id'] ?? 0),
                'date_creation'                 => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            $taskchecklists_id = $DB->insertId();

            foreach ($items as $item) {
                $DB->insert(PluginTregopluginsTaskChecklistItem::getTable(), [
                    'taskchecklists_id'       => $taskchecklists_id,
                    'source_templateitems_id' => (int) $item['id'],
                    'name_snapshot'           => $item['name'],
                    'instructions_snapshot'   => $item['instructions'] ?? '',
                    'position_snapshot'       => (int) $item['position'],
                    'is_required_snapshot'    => (int) $item['is_required'],
                    'is_active'               => 1,
                    'is_checked'              => 0,
                    'lock_version'            => 1,
                ]);
            }

            PluginTregopluginsChecklistEvent::log(
                $taskchecklists_id,
                null,
                $tickets_id,
                $tickettasks_id,
                PluginTregopluginsChecklistEvent::ACTION_CREATED_FROM_TEMPLATE,
                null,
                null,
                PluginTregopluginsChecklistEvent::ORIGIN_HOOK,
                ['checklisttemplates_id' => (int) $template->getID(), 'version' => (int) $template->fields['version']]
            );

            $DB->commit();

            return (int) $taskchecklists_id;
        } catch (\Throwable $e) {
            $DB->rollBack();

            // Concurrent creation raced us to the unique key: treat as success.
            $existing = PluginTregopluginsTaskChecklist::getForTask($tickettasks_id);
            if ($existing !== null) {
                return (int) $existing->getID();
            }

            trigger_error(
                'tregoplugins: falha ao materializar checklist da tarefa ' . $tickettasks_id . ': ' . $e->getMessage(),
                E_USER_WARNING
            );

            return null;
        }
    }

    /**
     * Called from Hooks::ITEM_PURGE on TicketTask: removes the checklist,
     * its items and its events for the purged task. Events are otherwise
     * append-only; this is the one documented exception (dependent cleanup
     * on parent purge), not a user-facing edit/delete of the audit trail.
     */
    public static function cleanupForPurgedTask(int $tickettasks_id): void
    {
        global $DB;

        $checklist = PluginTregopluginsTaskChecklist::getForTask($tickettasks_id);
        if ($checklist === null) {
            return;
        }

        $taskchecklists_id = (int) $checklist->getID();

        $DB->delete(PluginTregopluginsChecklistEvent::getTable(), ['taskchecklists_id' => $taskchecklists_id]);
        $DB->delete(PluginTregopluginsTaskChecklistItem::getTable(), ['taskchecklists_id' => $taskchecklists_id]);
        $DB->delete(PluginTregopluginsTaskChecklist::getTable(), ['id' => $taskchecklists_id]);
    }
}
