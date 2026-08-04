<?php

/**
 * The three completion policies a checklist snapshot can carry
 * (TRACK_ONLY/BLOCK_DONE/AUTO_DONE). Never touches Ticket status, only the
 * TicketTask's own `state` field, and only for the task the checklist
 * belongs to.
 */
class PluginTregopluginsChecklistCompletionPolicy
{
    /** @var array<int, bool> tickettasks_id currently being auto-completed by us */
    private static array $auto_completing = [];

    /**
     * Hooks::PRE_ITEM_UPDATE guard for TicketTask: blocks only a real
     * transition into Planning::DONE, and only under BLOCK_DONE policy with
     * a pending required item.
     */
    public static function guardPreUpdate(TicketTask $task): void
    {
        if (!isset($task->input['state']) || (int) $task->input['state'] !== Planning::DONE) {
            return;
        }
        if ((int) ($task->fields['state'] ?? 0) === Planning::DONE) {
            return; // already done, not a transition
        }
        if (self::$auto_completing[(int) $task->getID()] ?? false) {
            return; // our own AUTO_DONE update, never self-block
        }

        $checklist = PluginTregopluginsTaskChecklist::getForTask((int) $task->getID());
        if ($checklist === null) {
            return;
        }
        if ($checklist->fields['completion_policy_snapshot'] !== PluginTregopluginsChecklistTemplate::POLICY_BLOCK_DONE) {
            return;
        }

        $pending = PluginTregopluginsChecklistProgress::getPendingRequiredNames((int) $checklist->getID());
        if (empty($pending)) {
            return;
        }

        $task->input['state'] = $task->fields['state'] ?? Planning::TODO;
        Session::addMessageAfterRedirect(
            sprintf(
                _n(
                    'A tarefa não pode ser concluída: %d item obrigatório da checklist pendente (%s).',
                    'A tarefa não pode ser concluída: %d itens obrigatórios da checklist pendentes (%s).',
                    count($pending),
                    'tregoplugins'
                ),
                count($pending),
                implode(', ', array_slice($pending, 0, 3))
            ),
            false,
            ERROR
        );
    }

    /**
     * Called after a successful item toggle. Completes the task once, with
     * a same-process guard against the recursive PRE_ITEM_UPDATE our own
     * update() call triggers.
     */
    public static function maybeAutoComplete(int $taskchecklists_id): void
    {
        $checklist = new PluginTregopluginsTaskChecklist();
        if (!$checklist->getFromDB($taskchecklists_id)) {
            return;
        }
        if ($checklist->fields['completion_policy_snapshot'] !== PluginTregopluginsChecklistTemplate::POLICY_AUTO_DONE) {
            return;
        }

        $tickettasks_id = (int) $checklist->fields['tickettasks_id'];
        $task = new TicketTask();
        if (!$task->getFromDB($tickettasks_id)) {
            return;
        }
        if ((int) $task->fields['state'] === Planning::DONE) {
            return;
        }
        if (!PluginTregopluginsChecklistProgress::isEligibleForCompletion($taskchecklists_id)) {
            return;
        }

        self::$auto_completing[$tickettasks_id] = true;
        try {
            $task->update(['id' => $tickettasks_id, 'state' => Planning::DONE]);

            PluginTregopluginsChecklistEvent::log(
                $taskchecklists_id,
                null,
                (int) $checklist->fields['tickets_id'],
                $tickettasks_id,
                PluginTregopluginsChecklistEvent::ACTION_TASK_AUTO_COMPLETED,
                (string) Planning::TODO,
                (string) Planning::DONE,
                PluginTregopluginsChecklistEvent::ORIGIN_SYSTEM
            );
        } finally {
            unset(self::$auto_completing[$tickettasks_id]);
        }
    }
}
