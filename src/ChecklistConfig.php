<?php

/**
 * Plugin-owned single-row configuration for the task-checklist module.
 * Adds a "Modelo de Checklist" tab on Setup > General (Config) with a
 * toggle to enable the module. "Disabled means not called": every runtime
 * hook, tab, menu entry and asset of this module is only registered when
 * this flag is on (see plugin_init_tregoplugins() in setup.php), so a
 * disabled module has zero footprint on TicketTask/TaskTemplate/Ticket.
 */
class PluginTregopluginsChecklistConfig extends CommonDBTM
{
    public static $rightname = 'plugin_tregoplugins_checklisttemplate';

    public const TABLE = 'glpi_plugin_tregoplugins_checklistconfigs';

    private const CONFIG_ID = 1;

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Modelo de Checklist', 'tregoplugins');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Config && self::canView()) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Config && self::canView()) {
            self::showConfigForm();
        }
        return true;
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::getConfig()['enabled'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $config = new self();
        if ($config->getFromDB(self::CONFIG_ID)) {
            self::$cache = $config->fields;
        } else {
            self::$cache = ['id' => self::CONFIG_ID, 'enabled' => 0];
        }

        return self::$cache;
    }

    public function prepareInputForUpdate($input)
    {
        $input['enabled'] = (isset($input['enabled']) && (int) $input['enabled'] === 1) ? 1 : 0;

        return $input;
    }

    public function post_updateItem($history = true)
    {
        self::$cache = null;
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`      int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `enabled` tinyint NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
            $DB->insert(self::TABLE, ['id' => self::CONFIG_ID, 'enabled' => 0]);
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

    public static function installRights(): void
    {
        PluginTregopluginsChecklistProfile::installRights();
    }

    public static function uninstallRights(): void
    {
        PluginTregopluginsChecklistProfile::uninstallRights();
    }

    private static function showConfigForm(): void
    {
        $config  = self::getConfig();
        $canedit = self::canUpdate();

        echo "<div class='card mb-3'>";
        echo "<form method='post' action='" . Plugin::getWebDir('tregoplugins') . "/front/checklist.config.form.php'>";

        echo "<div class='card-header d-flex align-items-center'>";
        echo "<i class='ti ti-list-check me-2'></i>";
        echo "<span class='card-title mb-0'>" . self::getTypeName() . "</span>";
        echo "</div>";

        echo "<div class='card-body'>";

        echo "<div class='alert alert-info d-flex align-items-start mb-3'>";
        echo "<i class='ti ti-info-circle me-2 mt-1 flex-shrink-0'></i>";
        echo "<div>" . __('Permite vincular modelos de checklist a modelos de tarefa e materializar itens marcáveis dentro das tarefas dos chamados, sem alterar o fluxo nativo de categorias, modelos de chamado e modelos de tarefa.', 'tregoplugins') . "</div>";
        echo "</div>";

        echo "<div class='mb-3 form-check form-switch'>";
        echo "<input type='hidden' name='enabled' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='tregoplugins_checklist_enabled' name='enabled' value='1'"
            . ($config['enabled'] ? " checked" : "") . ($canedit ? "" : " disabled") . ">";
        echo "<label class='form-check-label' for='tregoplugins_checklist_enabled'>"
            . __('Ativar módulo de Modelo de Checklist', 'tregoplugins') . "</label>";
        echo "</div>";

        echo "</div>"; // card-body

        if ($canedit) {
            echo "<div class='card-footer text-end'>";
            echo Html::hidden('id', ['value' => self::CONFIG_ID]);
            echo "<button type='submit' name='update' class='btn btn-primary'>"
                . "<i class='ti ti-device-floppy me-1'></i>"
                . _sx('button', 'Save') . "</button>";
            echo "</div>";
        }

        Html::closeForm();
        echo "</div>"; // card
    }
}
