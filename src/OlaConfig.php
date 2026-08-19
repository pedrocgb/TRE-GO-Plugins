<?php

/**
 * Plugin-owned single-row configuration for the OLA TTO tracking module.
 * Adds a "Tempo para Atribuição com OLA" tab on Setup > General (Config)
 * with a toggle to enable the module. "Disabled means not called": every
 * runtime hook, page, and menu entry of this module is only active when
 * this flag is on (see plugin_init_tregoplugins() in setup.php), so a
 * disabled module stops OLA TTO tracking and hides the OLA Report page.
 *
 * Defaults to enabled on install, since OLA TTO tracking ran unconditionally
 * before this toggle existed — this keeps existing installs behaving exactly
 * as before until an admin explicitly opts out.
 */
class PluginTregopluginsOlaConfig extends CommonDBTM
{
    // Dedicated right (not PluginTregopluginsOlaReport::$rightname): that
    // right's matrix row in MainProfile only ever offers a READ checkbox,
    // so no profile can ever be granted UPDATE on it — this config's own
    // right exposes both READ and UPDATE in the matrix (see MainProfile.php).
    public static $rightname = 'plugin_tregoplugins_olaconfig';

    public const TABLE = 'glpi_plugin_tregoplugins_olaconfigs';

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
        return __('Tempo para Atribuição com OLA', 'tregoplugins');
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

        // Self-heal: the table may not exist yet on an install that only
        // received updated plugin files without going through a formal
        // reinstall/update. Querying a missing table throws on GLPI 11
        // (RuntimeException, unlike GLPI 10's silent failure), so ensure
        // the schema exists before reading it either way.
        self::ensureSchema();

        $config = new self();
        if ($config->getFromDB(self::CONFIG_ID)) {
            self::$cache = $config->fields;
        } else {
            self::$cache = ['id' => self::CONFIG_ID, 'enabled' => 1];
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
        self::ensureSchema();

        return true;
    }

    public static function ensureSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $query = "CREATE TABLE `" . self::TABLE . "` (
            `id`      int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `enabled` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
            COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

        $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
        $DB->insert(self::TABLE, ['id' => self::CONFIG_ID, 'enabled' => 1]);
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
        PluginTregopluginsTicketDispatchProfile::seedRight(self::$rightname, true);
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::$rightname]);
    }

    private static function showConfigForm(): void
    {
        $config  = self::getConfig();
        $canedit = self::canUpdate();

        echo "<div class='card mb-3'>";
        echo "<form method='post' action='" . Plugin::getWebDir('tregoplugins') . "/front/ola.config.form.php'>";

        echo "<div class='card-header d-flex align-items-center'>";
        echo "<i class='ti ti-clock-hour-4 me-2'></i>";
        echo "<span class='card-title mb-0'>" . self::getTypeName() . "</span>";
        echo "</div>";

        echo "<div class='card-body'>";

        echo "<div class='alert alert-info d-flex align-items-start mb-3'>";
        echo "<i class='ti ti-info-circle me-2 mt-1 flex-shrink-0'></i>";
        echo "<div>" . __('Controla o acompanhamento do Tempo para Atribuição (TTO) via calendário útil da OLA nos chamados e a página "Relatório OLA". Desativar interrompe o rastreamento e oculta o relatório.', 'tregoplugins') . "</div>";
        echo "</div>";

        echo "<div class='mb-3 form-check form-switch'>";
        echo "<input type='hidden' name='enabled' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='tregoplugins_ola_enabled' name='enabled' value='1'"
            . ($config['enabled'] ? " checked" : "") . ($canedit ? "" : " disabled") . ">";
        echo "<label class='form-check-label' for='tregoplugins_ola_enabled'>"
            . __('Ativar módulo de Tempo para Atribuição com OLA', 'tregoplugins') . "</label>";
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
