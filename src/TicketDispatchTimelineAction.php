<?php

/**
 * Renders the "Despachar chamado para Unidade Responsável" timeline button
 * via the official Hooks::TIMELINE_ACTIONS hook (beside Answer), for both
 * GLPI 10 and 11. The button is a plain POST form (not an AJAX call) so it
 * reuses GLPI's normal CSRF token / flash message / redirect plumbing
 * instead of a bespoke JS+JSON protocol.
 */
class PluginTregopluginsTicketDispatchTimelineAction
{
    /**
     * @param array{item?: CommonDBTM, rand?: int} $params
     */
    public static function render(array $params): void
    {
        $item = $params['item'] ?? null;

        if (!$item instanceof Ticket || $item->getID() <= 0) {
            return;
        }

        if (!PluginTregopluginsTicketDispatchConfig::isRunnable()) {
            return;
        }

        if (!PluginTregopluginsTicketDispatchProfile::canUseDispatchAction()) {
            return;
        }

        if (!$item->can($item->getID(), READ)) {
            return;
        }

        $eligibility = PluginTregopluginsTicketDispatchEligibility::evaluate($item);

        // Nothing to escalate: the ticket was already dispatched. Showing a
        // disabled button here would just be noise, so the action is
        // omitted entirely.
        $hidden_reasons = [
            PluginTregopluginsTicketDispatchEligibility::ALREADY_DISPATCHED,
        ];
        if (!$eligibility['allowed'] && in_array($eligibility['reason_code'], $hidden_reasons, true)) {
            return;
        }

        $label = __('Despachar Chamado', 'tregoplugins');
        $full_label = __('Despachar chamado para Unidade Responsável', 'tregoplugins');
        $icon = PluginTregopluginsIcon::html('forward', 16, 'me-1');
        $form_url = Plugin::getWebDir('tregoplugins') . '/front/ticketdispatch.form.php';

        echo "<li class='plugin-tregoplugins-dispatch-action" . ($eligibility['allowed'] ? '' : ' plugin-tregoplugins-disabled') . "'>";
        echo "<div class='plugin-tregoplugins-dispatch-slot'>";

        echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='plugin-tregoplugins-dispatch-form'>";
        echo Html::hidden('tickets_id', ['value' => $item->getID()]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        $attributes = [
            'type'  => $eligibility['allowed'] ? 'button' : 'submit',
            'class' => 'btn btn-primary mb-2 plugin-tregoplugins-dispatch-btn',
            'title' => $eligibility['allowed'] ? $full_label : $eligibility['reason_label'],
        ];
        if (!$eligibility['allowed']) {
            $attributes['disabled'] = 'disabled';
        }

        echo "<button";
        foreach ($attributes as $name => $value) {
            echo " " . $name . "='" . Html::entities_deep((string) $value) . "'";
        }
        echo ">" . $icon . "<span>" . $label . "</span></button>";
        echo "</form>";

        if ($eligibility['allowed']) {
            $group_names = array_map(static function (int $groups_id): string {
                $group = new Group();
                return $group->getFromDB($groups_id) ? (string) $group->fields['name'] : '';
            }, $eligibility['calculated_group_ids']);
            $confirm_text = sprintf(__('Enviar para %s?', 'tregoplugins'), implode(', ', array_filter($group_names)));

            echo "<div class='plugin-tregoplugins-dispatch-confirm'>";
            echo "<span class='plugin-tregoplugins-dispatch-confirm-text'>" . Html::entities_deep($confirm_text) . "</span>";
            echo "<button type='button' class='btn btn-success btn-sm plugin-tregoplugins-dispatch-yes'>" . __('Sim', 'tregoplugins') . "</button>";
            echo "<button type='button' class='btn btn-secondary btn-sm plugin-tregoplugins-dispatch-no'>" . __('Não', 'tregoplugins') . "</button>";
            echo "</div>";
        }

        echo "</div>"; // slot
        echo "</li>";
    }
}
