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
        $label = __('Despachar chamado para Unidade Responsável', 'tregoplugins');
        $icon = PluginTregopluginsIcon::html('forward', 16, 'me-1');
        $form_url = Plugin::getWebDir('tregoplugins') . '/front/ticketdispatch.form.php';

        echo "<li class='plugin-tregoplugins-dispatch-action" . ($eligibility['allowed'] ? '' : ' plugin-tregoplugins-disabled') . "'>";
        echo "<div class='plugin-tregoplugins-dispatch-slot'>";

        echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='plugin-tregoplugins-dispatch-form'>";
        echo Html::hidden('tickets_id', ['value' => $item->getID()]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        $attributes = [
            'type'  => $eligibility['allowed'] ? 'button' : 'submit',
            'class' => 'btn btn-warning mb-2 plugin-tregoplugins-dispatch-btn',
            'title' => $eligibility['allowed'] ? $label : $eligibility['reason_label'],
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
            echo "<div class='plugin-tregoplugins-dispatch-confirm'>";
            echo "<span class='plugin-tregoplugins-dispatch-confirm-text'>"
                . __('O chamado será reavaliado pelas regras de abertura e movido para a unidade responsável calculada. Deseja continuar?', 'tregoplugins')
                . "</span>";
            echo "<button type='button' class='btn btn-success btn-sm plugin-tregoplugins-dispatch-yes'>" . __('Sim', 'tregoplugins') . "</button>";
            echo "<button type='button' class='btn btn-secondary btn-sm plugin-tregoplugins-dispatch-no'>" . __('Não', 'tregoplugins') . "</button>";
            echo "</div>";
        }

        echo "</div>"; // slot
        echo "</li>";
    }
}
