<?php

/**
 * Enriches TicketTask cards in the ticket timeline with their materialized
 * checklist, via the version-agnostic Hooks::SHOW_IN_TIMELINE hook (present
 * unchanged since early GLPI 10 and not deprecated; GLPI 11's install was
 * not available to verify a TIMELINE_ITEMS-specific shape against, so this
 * hook was kept as the single, tested implementation for both branches —
 * see docs handed to the next reviewer for the compatibility caveat).
 *
 * SHOW_IN_TIMELINE fires after CommonITILObject::getTimelineItems() has
 * already filtered tasks by canViewItem(), so no extra privacy check is
 * needed here beyond the module's own read right. Loads every checklist for
 * the whole timeline in one batch query (no N+1 per task card).
 */
class PluginTregopluginsChecklistTimelinePresenter
{
    /**
     * @param array{item?: CommonITILObject, timeline?: array<string, mixed>} $params
     */
    public static function render(array $params): void
    {
        if (!($params['item'] ?? null) instanceof Ticket) {
            return;
        }
        if (!isset($params['timeline']) || !is_array($params['timeline'])) {
            return;
        }
        if (!PluginTregopluginsChecklistProfile::canViewTaskChecklist()) {
            return;
        }

        $tickettasks_ids = [];
        foreach ($params['timeline'] as $entry) {
            if (($entry['type'] ?? null) === TicketTask::class) {
                $tickettasks_ids[] = (int) ($entry['item']['id'] ?? 0);
            }
        }
        if (empty($tickettasks_ids)) {
            return;
        }

        $checklists = PluginTregopluginsTaskChecklist::getForTasks($tickettasks_ids);
        if (empty($checklists)) {
            return;
        }

        $checklists_ids = array_map(static fn (array $c): int => (int) $c['id'], $checklists);
        $items_by_checklist = PluginTregopluginsTaskChecklistItem::getForChecklists($checklists_ids);
        $can_edit = PluginTregopluginsChecklistProfile::canUpdateTaskChecklist();

        foreach ($params['timeline'] as $key => &$entry) {
            if (($entry['type'] ?? null) !== TicketTask::class) {
                continue;
            }

            $tickettasks_id = (int) ($entry['item']['id'] ?? 0);
            if (!isset($checklists[$tickettasks_id])) {
                continue;
            }

            $checklist = $checklists[$tickettasks_id];
            $items = $items_by_checklist[(int) $checklist['id']] ?? [];

            $entry['item']['content'] = ($entry['item']['content'] ?? '')
                . self::buildFragment($checklist, $items, $can_edit);
        }
        unset($entry);
    }

    /**
     * @param array<string, mixed> $checklist
     * @param array<int, array<string, mixed>> $items
     */
    private static function buildFragment(array $checklist, array $items, bool $can_edit): string
    {
        $taskchecklists_id = (int) $checklist['id'];
        $done = 0;
        foreach ($items as $item) {
            if ((int) $item['is_checked'] === 1) {
                $done++;
            }
        }
        $total = count($items);

        $html = "<div class='plugin-tregoplugins-checklist' data-checklist-id='" . $taskchecklists_id . "'>";
        $html .= "<div class='plugin-tregoplugins-checklist-header'>";
        $html .= "<i class='ti ti-list-check me-1'></i>";
        $html .= "<span class='plugin-tregoplugins-checklist-name'>" . Html::entities_deep($checklist['name_snapshot']) . "</span>";
        $html .= "<span class='plugin-tregoplugins-checklist-count' aria-live='polite' data-role='progress-count'>"
            . sprintf(__('%1$d/%2$d', 'tregoplugins'), $done, $total) . "</span>";
        $html .= "</div>";

        $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $html .= "<div class='progress plugin-tregoplugins-checklist-progress' role='progressbar' aria-valuemin='0' aria-valuemax='100' aria-valuenow='" . $pct . "'>";
        $html .= "<div class='progress-bar' style='width:" . $pct . "%'></div>";
        $html .= "</div>";

        $html .= "<ul class='plugin-tregoplugins-checklist-items list-unstyled'>";
        foreach ($items as $item) {
            $item_id = (int) $item['id'];
            $checked = (int) $item['is_checked'] === 1;
            $required = (int) $item['is_required_snapshot'] === 1;

            $html .= "<li class='plugin-tregoplugins-checklist-item" . ($required ? ' plugin-tregoplugins-required' : '') . "'>";
            $html .= "<label class='form-check'>";
            $html .= "<input type='checkbox' class='form-check-input plugin-tregoplugins-checklist-toggle'"
                . " data-item-id='" . $item_id . "'"
                . " data-lock-version='" . (int) $item['lock_version'] . "'"
                . ($checked ? " checked" : "")
                . ($can_edit ? "" : " disabled") . ">";
            $html .= "<span class='form-check-label'>" . Html::entities_deep($item['name_snapshot'])
                . ($required ? " <span class='plugin-tregoplugins-required-badge'>*</span>" : "") . "</span>";
            $html .= "</label>";
            if (!empty($item['instructions_snapshot'])) {
                $html .= "<div class='plugin-tregoplugins-checklist-instructions text-muted small'>"
                    . Html::entities_deep($item['instructions_snapshot']) . "</div>";
            }
            if ($checked && (int) $item['checked_by'] > 0) {
                $user = new User();
                $user_name = $user->getFromDB((int) $item['checked_by']) ? $user->getFriendlyName() : '';
                $html .= "<div class='plugin-tregoplugins-checklist-meta text-muted small'>"
                    . sprintf(__('Marcado por %1$s em %2$s', 'tregoplugins'), Html::entities_deep($user_name), Html::convDateTime($item['checked_at']))
                    . "</div>";
            }
            $html .= "</li>";
        }
        $html .= "</ul>";
        $html .= "</div>";

        return $html;
    }
}
