<?php

/**
 * A conditional link between a native TaskTemplate and a
 * PluginTregopluginsChecklistTemplate. Several bindings can target the same
 * TaskTemplate (different entity/ticket type/ITIL category/priority); only
 * one wins per TicketTask, decided by PluginTregopluginsChecklistBindingResolver.
 * Adds the "Modelo de Checklist" tab on TaskTemplate.
 */
class PluginTregopluginsChecklistBinding extends CommonDBTM
{
    public static $rightname = PluginTregopluginsChecklistProfile::TEMPLATE_RIGHTNAME;

    public const TABLE = 'glpi_plugin_tregoplugins_tasktemplatebindings';

    public const ANY_TICKET_TYPE = 0;
    public const ANY_ITILCATEGORY = 0;

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Modelo de Checklist', 'tregoplugins');
    }

    public static function canView(): bool
    {
        return PluginTregopluginsChecklistProfile::canViewTemplates();
    }

    public static function canCreate(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public static function canUpdate(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public static function canPurge(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof TaskTemplate && $item->getID() > 0 && self::canView()) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof TaskTemplate && $item->getID() > 0 && self::canView()) {
            self::showForTaskTemplate($item);
        }
        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalizeAndValidate($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizeAndValidate($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function normalizeAndValidate(array $input)
    {
        $input['is_recursive'] = isset($input['is_recursive']) && (int) $input['is_recursive'] === 1 ? 1 : 0;
        $input['is_active']    = isset($input['is_active']) && (int) $input['is_active'] === 1 ? 1 : 0;
        $input['ticket_type']       = (int) ($input['ticket_type'] ?? self::ANY_TICKET_TYPE);
        $input['itilcategories_id'] = (int) ($input['itilcategories_id'] ?? self::ANY_ITILCATEGORY);
        $input['priority']          = (int) ($input['priority'] ?? 0);
        $input['entities_id']       = (int) ($input['entities_id'] ?? Session::getActiveEntity());

        if (($input['tasktemplates_id'] ?? 0) <= 0 || ($input['checklisttemplates_id'] ?? 0) <= 0) {
            return false;
        }

        if ($this->hasIndistinguishableSibling($input)) {
            Session::addMessageAfterRedirect(
                __('Já existe um vínculo idêntico (mesma entidade, tipo, categoria e prioridade) para este modelo de tarefa.', 'tregoplugins'),
                false,
                ERROR
            );
            return false;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasIndistinguishableSibling(array $input): bool
    {
        global $DB;

        $where = [
            'tasktemplates_id'  => (int) $input['tasktemplates_id'],
            'entities_id'       => (int) $input['entities_id'],
            'is_recursive'      => (int) $input['is_recursive'],
            'ticket_type'       => (int) $input['ticket_type'],
            'itilcategories_id' => (int) $input['itilcategories_id'],
            'priority'          => (int) $input['priority'],
            'is_active'         => 1,
        ];

        if (!$this->isNewItem()) {
            $where['id'] = ['<>', $this->getID()];
        }

        return countElementsInTable(self::TABLE, $where) > 0;
    }

    private static function showForTaskTemplate(TaskTemplate $tasktemplate): void
    {
        global $DB;

        $can_edit = self::canUpdate();
        $form_url = Plugin::getWebDir('tregoplugins') . '/front/checklistbinding.form.php';
        $ticket_types = [
            self::ANY_TICKET_TYPE => __('Qualquer tipo', 'tregoplugins'),
            Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
            Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
        ];

        echo "<div class='spaced'>";
        echo "<table class='table'>";
        echo "<thead><tr>";
        echo "<th>" . PluginTregopluginsChecklistTemplate::getTypeName() . "</th>";
        echo "<th>" . __('Entidade') . "</th>";
        echo "<th>" . __('Tipo') . "</th>";
        echo "<th>" . _n('Categoria', 'Categorias', 1) . "</th>";
        echo "<th>" . __('Prioridade', 'tregoplugins') . "</th>";
        echo "<th>" . __('Ativo') . "</th>";
        if ($can_edit) {
            echo "<th></th>";
        }
        echo "</tr></thead><tbody>";

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tasktemplates_id' => $tasktemplate->getID()],
            'ORDER' => 'priority DESC, id ASC',
        ]);

        foreach ($iterator as $row) {
            echo "<tr>";
            echo "<td>" . Html::entities_deep(Dropdown::getDropdownName(PluginTregopluginsChecklistTemplate::getTable(), $row['checklisttemplates_id'])) . "</td>";
            echo "<td>" . Html::entities_deep(Dropdown::getDropdownName(Entity::getTable(), $row['entities_id'])) . ($row['is_recursive'] ? ' (' . __('recursivo') . ')' : '') . "</td>";
            echo "<td>" . Html::entities_deep($ticket_types[(int) $row['ticket_type']] ?? '') . "</td>";
            echo "<td>" . ((int) $row['itilcategories_id'] === 0 ? __('Qualquer', 'tregoplugins') : Html::entities_deep(Dropdown::getDropdownName(ITILCategory::getTable(), $row['itilcategories_id']))) . "</td>";
            echo "<td>" . (int) $row['priority'] . "</td>";
            echo "<td>" . ($row['is_active'] ? __('Sim') : __('Não')) . "</td>";
            if ($can_edit) {
                echo "<td>";
                echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='d-inline'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo "<button type='submit' name='purge' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i></button>";
                echo "</form>";
                echo "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table>";

        if ($can_edit) {
            echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='row g-2 align-items-end'>";
            echo Html::hidden('tasktemplates_id', ['value' => $tasktemplate->getID()]);

            echo "<div class='col-md-3'>";
            PluginTregopluginsChecklistTemplate::dropdown(['name' => 'checklisttemplates_id', 'entity' => $tasktemplate->fields['entities_id'] ?? Session::getActiveEntity()]);
            echo "</div>";

            echo "<div class='col-md-2'>";
            Entity::dropdown(['name' => 'entities_id', 'value' => Session::getActiveEntity()]);
            echo "</div>";

            echo "<div class='col-md-2'>";
            Dropdown::showFromArray('ticket_type', $ticket_types, ['value' => self::ANY_TICKET_TYPE]);
            echo "</div>";

            echo "<div class='col-md-2'>";
            ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => 0, 'display_emptychoice' => true, 'emptylabel' => __('Qualquer', 'tregoplugins')]);
            echo "</div>";

            echo "<div class='col-md-1'>";
            echo "<input type='number' class='form-control' name='priority' value='0' title='" . __('Prioridade', 'tregoplugins') . "'>";
            echo "</div>";

            echo "<div class='col-md-1'>";
            echo "<button type='submit' name='add' class='btn btn-primary w-100'><i class='ti ti-plus'></i></button>";
            echo "</div>";

            echo "<div class='col-md-1 form-check'>";
            echo "<input type='checkbox' class='form-check-input' id='binding_is_active' name='is_active' value='1' checked>";
            echo "<label class='form-check-label' for='binding_is_active'>" . __('Ativo') . "</label>";
            echo "</div>";
            echo "<div class='col-md-1 form-check'>";
            echo "<input type='checkbox' class='form-check-input' id='binding_is_recursive' name='is_recursive' value='1'>";
            echo "<label class='form-check-label' for='binding_is_recursive'>" . __('Recursivo') . "</label>";
            echo "</div>";

            echo "</form>";
        }

        echo "</div>";
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                    int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `tasktemplates_id`      int {$default_key_sign} NOT NULL,
                `checklisttemplates_id` int {$default_key_sign} NOT NULL,
                `entities_id`           int {$default_key_sign} NOT NULL DEFAULT 0,
                `is_recursive`          tinyint NOT NULL DEFAULT 0,
                `ticket_type`           int NOT NULL DEFAULT 0,
                `itilcategories_id`     int {$default_key_sign} NOT NULL DEFAULT 0,
                `priority`              int NOT NULL DEFAULT 0,
                `is_active`             tinyint NOT NULL DEFAULT 1,
                `date_creation`         timestamp NULL DEFAULT NULL,
                `date_mod`              timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `tasktemplates_id_is_active` (`tasktemplates_id`, `is_active`),
                KEY `checklisttemplates_id` (`checklisttemplates_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
        }

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQueryOrDie("DROP TABLE IF EXISTS `" . self::TABLE . "`", 'Drop ' . self::TABLE);
        }

        return true;
    }
}
