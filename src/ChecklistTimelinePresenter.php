<?php

/**
 * Enriches TicketTask cards in the ticket timeline with their materialized
 * checklist.
 *
 * Not implemented via Hooks::SHOW_IN_TIMELINE: that hook only lets a plugin
 * mutate the entry's `content` field, which the timeline template renders
 * through a `safe_html`/purifier filter — it strips <input>/<form>, so any
 * checkbox injected that way silently disappears (confirmed against
 * templates/components/itilobject/timeline/timeline.html.twig: the read-only
 * branch does `{{ entry_i['content']|safe_html }}`).
 *
 * Hooks::PRE_SHOW_ITEM/POST_SHOW_ITEM (both `since 10.0.0`, confirmed present
 * unchanged in the GLPI 10 source used to build this plugin) are called via
 * Twig's `call_plugin_hook()` without `return_result`, so the callback's
 * `echo` output goes straight to the response — never sanitized. POST_SHOW_ITEM
 * fires just before the `.timeline-item` wrapper closes (after the card's own
 * content), so the checklist reads as "attached to" the task card without
 * fighting the purifier.
 */
class PluginTregopluginsChecklistTimelinePresenter
{
    /**
     * @param array{item?: CommonDBTM, options?: array<string, mixed>} $params
     */
    public static function render(array $params): void
    {
        $task = $params['item'] ?? null;
        if (!$task instanceof TicketTask || (int) $task->getID() <= 0) {
            return;
        }
        if (!PluginTregopluginsChecklistProfile::canViewTaskChecklist()) {
            return;
        }

        $checklist = PluginTregopluginsTaskChecklist::getForTask((int) $task->getID());
        if ($checklist === null) {
            return;
        }

        $items = PluginTregopluginsTaskChecklistItem::getForChecklist((int) $checklist->getID());
        if (empty($items)) {
            return;
        }

        $can_edit = PluginTregopluginsChecklistProfile::canUpdateTaskChecklist() && $task->canUpdateItem();

        echo self::buildFragment($checklist->fields, $items, $can_edit);
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
