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
        $icon_url = Plugin::getWebDir('tregoplugins') . '/public/img/lucide/forward.svg';
        $form_url = Plugin::getWebDir('tregoplugins') . '/front/ticketdispatch.form.php';

        echo "<li class='plugin-tregoplugins-dispatch-action'>";
        echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='d-inline'>";
        echo Html::hidden('tickets_id', ['value' => $item->getID()]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        $attributes = [
            'type'  => 'submit',
            'class' => 'btn btn-outline-secondary ms-2 mb-2 plugin-tregoplugins-dispatch-btn',
            'title' => $eligibility['allowed'] ? $label : $eligibility['reason_label'],
        ];
        if (!$eligibility['allowed']) {
            $attributes['disabled'] = 'disabled';
        } else {
            $attributes['data-plugin-tregoplugins-confirm'] = __('O chamado será reavaliado pelas regras de abertura e movido para a unidade responsável calculada. Deseja continuar?', 'tregoplugins');
        }

        echo "<button";
        foreach ($attributes as $name => $value) {
            echo " " . $name . "='" . Html::entities_deep((string) $value) . "'";
        }
        echo ">";
        echo "<img src='" . Html::entities_deep($icon_url) . "' class='plugin-tregoplugins-dispatch-icon' alt='' aria-hidden='true' width='16' height='16'>";
        echo "<span class='ms-1'>" . $label . "</span>";
        echo "</button>";
        echo "</form>";
        echo "</li>";
    }
}
