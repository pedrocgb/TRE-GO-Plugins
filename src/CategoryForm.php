<?php

/**
 * Renders plugin-specific controls in the ITIL category form.
 */
class PluginTregopluginsCategoryForm
{
    public static function postItemForm(array $params): void
    {
        $item = $params['item'] ?? null;

        if ($item instanceof ITILCategory) {
            self::renderCategoryFields($item);
            return;
        }

        if ($item instanceof ITILSolution) {
            self::syncSolutionTemplateDropdown($item, $params['options']['item'] ?? null);
        }
    }

    private static function renderCategoryFields(ITILCategory $item): void
    {
        $field_values = $item->getID() > 0
            ? PluginTregopluginsCategoryConfig::getCategorySettings((int) $item->getID())
            : [
                'request'            => 0,
                'incident'           => 0,
                'auto_link_knowbase' => true,
            ];
        $entity_id = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());
        $can_view_solution_templates = SolutionTemplate::canView();

        $request_dropdown_html = $can_view_solution_templates
            ? self::renderSolutionTemplateDropdownHtml(
                PluginTregopluginsCategoryConfig::FORM_FIELD_REQUEST,
                (int) $field_values['request'],
                $entity_id
            )
            : '';

        $incident_dropdown_html = $can_view_solution_templates
            ? self::renderSolutionTemplateDropdownHtml(
                PluginTregopluginsCategoryConfig::FORM_FIELD_INCIDENT,
                (int) $field_values['incident'],
                $entity_id
            )
            : '';

        $auto_link_checked = $field_values['auto_link_knowbase'] ? 'checked' : '';

        echo "<div class='col-12'>";
        echo "  <div class='card mt-3'>";
        echo "    <div class='card-header'>Automações da categoria ITIL</div>";
        echo "    <div class='card-body row'>";

        echo "      <div class='col-12'>";
        echo "        <input type='hidden' name='" . PluginTregopluginsCategoryConfig::FORM_FIELD_AUTO_LINK_KB . "' value='0'>";
        echo "        <div class='form-check form-switch'>";
        echo "          <input class='form-check-input' type='checkbox' id='plugin-tregoplugins-auto-link-kb' name='" . PluginTregopluginsCategoryConfig::FORM_FIELD_AUTO_LINK_KB . "' value='1' {$auto_link_checked}>";
        echo "          <label class='form-check-label' for='plugin-tregoplugins-auto-link-kb'>";
        echo               'Vincular Base de Conhecimento automaticamente';
        echo "          </label>";
        echo "        </div>";
        echo "        <div class='form-text'>";
        echo             'Quando ativado, o primeiro artigo disponivel na categoria de base configurada sera vinculado automaticamente ao ticket na criacao.';
        echo "        </div>";
        echo "      </div>";

        if ($can_view_solution_templates) {
            echo "      <div class='col-12 col-xxl-6 mt-3'>";
            echo "        <label class='form-label'>";
            echo             'Modelo de solucao para tickets do tipo Solicitacao';
            echo "        </label>";
            echo              $request_dropdown_html;
            echo "        <div class='form-text'>";
            echo             'Aplicado ao abrir, resolver ou fechar um ticket de solicitacao sem solucao preenchida.';
            echo "        </div>";
            echo "      </div>";

            echo "      <div class='col-12 col-xxl-6 mt-3'>";
            echo "        <label class='form-label'>";
            echo             'Modelo de solucao para tickets do tipo Incidente';
            echo "        </label>";
            echo              $incident_dropdown_html;
            echo "        <div class='form-text'>";
            echo             'Aplicado ao abrir, resolver ou fechar um ticket de incidente sem solucao preenchida.';
            echo "        </div>";
            echo "      </div>";
        }

        echo "    </div>";
        echo "  </div>";
        echo "</div>";
    }

    private static function renderSolutionTemplateDropdownHtml(
        string $field_name,
        int $field_value,
        int $entity_id
    ): string {
        ob_start();
        SolutionTemplate::dropdown([
            'name'                => $field_name,
            'value'               => $field_value,
            'entity'              => $entity_id,
            'display_emptychoice' => true,
            'rand'                => mt_rand(),
        ]);

        return (string) ob_get_clean();
    }

    private static function syncSolutionTemplateDropdown(ITILSolution $item, mixed $parent): void
    {
        if (!$parent instanceof Ticket || $item->getID() > 0) {
            return;
        }

        $solution_template_id = PluginTregopluginsCategoryConfig::getSolutionTemplateIdForTicket($parent);
        if ($solution_template_id <= 0) {
            return;
        }

        $template = new SolutionTemplate();
        if (!$template->getFromDB($solution_template_id)) {
            return;
        }

        $template_name = json_encode((string) $template->getName(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $template_id = (int) $solution_template_id;

        echo "<script>
            $(function () {
                var form = $('.itilsolution form').last();
                var select = form.find('[id^=\"dropdown_solutiontemplates_id\"]');

                if (!select.length || select.data('tregopluginsAutoSelected')) {
                    return;
                }

                var option = new Option({$template_name}, {$template_id}, true, true);
                select.append(option).trigger('change');
                select.data('tregopluginsAutoSelected', true);
            });
        </script>";
    }
}
