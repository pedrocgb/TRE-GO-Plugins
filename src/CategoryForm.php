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
        if (!SolutionTemplate::canView()) {
            return;
        }

        $field_values = $item->getID() > 0
            ? PluginTregopluginsCategoryConfig::getSolutionTemplateIdsForCategory((int) $item->getID())
            : ['request' => 0, 'incident' => 0];
        $entity_id = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());

        ob_start();
        SolutionTemplate::dropdown([
            'name'                => PluginTregopluginsCategoryConfig::FORM_FIELD_REQUEST,
            'value'               => $field_values['request'],
            'entity'              => $entity_id,
            'display_emptychoice' => true,
            'rand'                => mt_rand(),
        ]);
        $request_dropdown_html = ob_get_clean();

        ob_start();
        SolutionTemplate::dropdown([
            'name'                => PluginTregopluginsCategoryConfig::FORM_FIELD_INCIDENT,
            'value'               => $field_values['incident'],
            'entity'              => $entity_id,
            'display_emptychoice' => true,
            'rand'                => mt_rand(),
        ]);
        $incident_dropdown_html = ob_get_clean();

        echo "<div class='col-12'>";
        echo "  <div class='card mt-3'>";
        echo "    <div class='card-header'>" . __('ITIL category automations') . "</div>";
        echo "    <div class='card-body row'>";

        echo "      <div class='col-12 col-xxl-6'>";
        echo "        <label class='form-label'>";
        echo             __('Solution template for request tickets');
        echo "        </label>";
        echo              $request_dropdown_html;
        echo "        <div class='form-text'>";
        echo             __('Applied when a request ticket is solved or closed without an existing solution.');
        echo "        </div>";
        echo "      </div>";

        echo "      <div class='col-12 col-xxl-6 mt-3 mt-xxl-0'>";
        echo "        <label class='form-label'>";
        echo             __('Solution template for incident tickets');
        echo "        </label>";
        echo              $incident_dropdown_html;
        echo "        <div class='form-text'>";
        echo             __('Applied when an incident ticket is solved or closed without an existing solution.');
        echo "        </div>";
        echo "      </div>";

        echo "    </div>";
        echo "  </div>";
        echo "</div>";
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
