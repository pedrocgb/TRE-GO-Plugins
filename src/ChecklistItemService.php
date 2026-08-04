<?php

/**
 * Toggles/reopens a single PluginTregopluginsTaskChecklistItem. Owns object-
 * level authorization (walks item -> checklist -> TicketTask -> Ticket,
 * never trusting client-sent parent ids) and the optimistic-concurrency
 * update (conditioned on `lock_version`), per the module's security spec.
 */
class PluginTregopluginsChecklistItemService
{
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_CONFLICT = 'conflict';
    public const ERROR_INACTIVE = 'inactive';

    /**
     * @return array{
     *     ok: bool,
     *     error?: string,
     *     item?: array<string, mixed>,
     *     progress?: array{done:int,total:int,pending_required:string[]},
     * }
     */
    public static function toggle(int $items_id, bool $checked, int $expected_lock_version, ?string $note = null): array
    {
        global $DB;

        $item = new PluginTregopluginsTaskChecklistItem();
        if (!$item->getFromDB($items_id)) {
            return ['ok' => false, 'error' => self::ERROR_NOT_FOUND];
        }

        $checklist = new PluginTregopluginsTaskChecklist();
        if (!$checklist->getFromDB((int) $item->fields['taskchecklists_id'])) {
            return ['ok' => false, 'error' => self::ERROR_NOT_FOUND];
        }

        if (!$checklist->isUpdatableByCurrentUser()) {
            return ['ok' => false, 'error' => self::ERROR_FORBIDDEN];
        }

        if ((int) $item->fields['is_active'] !== 1) {
            return ['ok' => false, 'error' => self::ERROR_INACTIVE];
        }

        // Idempotent no-op: already in the requested state (handles
        // double-click/repeat submits without touching lock_version).
        if ((int) $item->fields['is_checked'] === ($checked ? 1 : 0)) {
            return [
                'ok'       => true,
                'item'     => self::toPublicArray($item->fields),
                'progress' => PluginTregopluginsChecklistProgress::compute((int) $checklist->getID()),
            ];
        }

        $old_state = (int) $item->fields['is_checked'] === 1 ? 'checked' : 'unchecked';
        $new_state = $checked ? 'checked' : 'unchecked';

        $update = [
            'is_checked'   => $checked ? 1 : 0,
            'checked_by'   => $checked ? (int) (Session::getLoginUserID() ?: 0) : 0,
            'checked_at'   => $checked ? ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')) : null,
            'note'         => $note ?? $item->fields['note'],
            'lock_version' => ((int) $item->fields['lock_version']) + 1,
        ];

        $affected = $DB->update(
            PluginTregopluginsTaskChecklistItem::getTable(),
            $update,
            ['id' => $items_id, 'lock_version' => $expected_lock_version]
        );

        if (!$affected || $DB->affectedRows() === 0) {
            $item->getFromDB($items_id);
            return [
                'ok'       => false,
                'error'    => self::ERROR_CONFLICT,
                'item'     => self::toPublicArray($item->fields),
                'progress' => PluginTregopluginsChecklistProgress::compute((int) $checklist->getID()),
            ];
        }

        $item->getFromDB($items_id);

        PluginTregopluginsChecklistEvent::log(
            (int) $checklist->getID(),
            $items_id,
            (int) $checklist->fields['tickets_id'],
            (int) $checklist->fields['tickettasks_id'],
            $checked ? PluginTregopluginsChecklistEvent::ACTION_ITEM_CHECKED : PluginTregopluginsChecklistEvent::ACTION_ITEM_REOPENED,
            $old_state,
            $new_state,
            PluginTregopluginsChecklistEvent::ORIGIN_UI
        );

        if ($checked) {
            PluginTregopluginsChecklistCompletionPolicy::maybeAutoComplete((int) $checklist->getID());
        }

        return [
            'ok'       => true,
            'item'     => self::toPublicArray($item->fields),
            'progress' => PluginTregopluginsChecklistProgress::compute((int) $checklist->getID()),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function toPublicArray(array $fields): array
    {
        return [
            'id'            => (int) $fields['id'],
            'is_checked'    => (int) $fields['is_checked'] === 1,
            'checked_by'    => (int) $fields['checked_by'],
            'checked_at'    => $fields['checked_at'],
            'note'          => $fields['note'],
            'lock_version'  => (int) $fields['lock_version'],
        ];
    }
}
