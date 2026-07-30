<?php

/**
 * Plugin-owned single-row configuration for the knowledge base visibility
 * guard (workaround for GLPI's KnowbaseItem::getVisibilityCriteriaKB() bug,
 * see PluginTregopluginsKbVisibilityGuard).
 *
 * Adds a "Base de Conhecimento" tab on Setup > General (Config) with a
 * toggle to enable/disable the guard at runtime, without deactivating the
 * whole plugin. Access to view/edit that tab is gated by its own profile
 * right (plugin_tregoplugins_kbvisibility) so it can be granted or denied
 * per profile independently of the plugin's other modules.
 */
class PluginTregopluginsKbVisibilityConfig extends CommonDBTM
{
    public static $rightname = 'plugin_tregoplugins_kbvisibility';

    public const TABLE = 'glpi_plugin_tregoplugins_kbvisibilityconfigs';

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
        return 'Base de Conhecimento - Visibilidade';
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

    /**
     * Whether the knowledge base visibility guard is currently enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) (self::getConfig()['enabled'] ?? true);
    }

    /**
     * Fetch (and cache for the current request) the single configuration row.
     *
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
            // Fail safe: if the config row is missing for any reason, keep
            // the guard active rather than silently reopening the bug.
            self::$cache = ['id' => self::CONFIG_ID, 'enabled' => 1];
        }

        return self::$cache;
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
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

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQueryOrDie(
                "DROP TABLE IF EXISTS `" . self::TABLE . "`",
                'Drop ' . self::TABLE
            );
        }

        return true;
    }

    public static function installRights(): void
    {
        global $DB, $GLPI_CACHE;

        if (!$DB->tableExists(ProfileRight::getTable())) {
            return;
        }

        $profiles = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Profile::getTable(),
        ]);

        foreach ($profiles as $profile) {
            $profiles_id = (int) ($profile['id'] ?? 0);
            if ($profiles_id <= 0) {
                continue;
            }

            if (
                countElementsInTable(
                    ProfileRight::getTable(),
                    ['profiles_id' => $profiles_id, 'name' => self::$rightname]
                ) > 0
            ) {
                continue;
            }

            $rights = self::isProfileManager($profiles_id) ? (READ | UPDATE) : 0;

            $DB->insert(
                ProfileRight::getTable(),
                [
                    'profiles_id' => $profiles_id,
                    'name'        => self::$rightname,
                    'rights'      => $rights,
                ]
            );
        }

        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set('all_possible_rights', []);
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::$rightname]);
    }

    private static function isProfileManager(int $profiles_id): bool
    {
        global $DB;

        if ($profiles_id <= 0) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => Profile::$rightname,
            ],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return false;
        }

        $row = $iterator->current();
        return (((int) ($row['rights'] ?? 0)) & UPDATE) === UPDATE;
    }

    private static function showConfigForm(): void
    {
        $config  = self::getConfig();
        $canedit = self::canUpdate();

        echo "<div class='card mb-3'>";
        echo "<form method='post' action='" . Plugin::getWebDir('tregoplugins') . "/front/kbvisibility.config.form.php'>";

        echo "<div class='card-header d-flex align-items-center'>";
        echo PluginTregopluginsIcon::html('eye', 20, 'me-2');
        echo "<span class='card-title mb-0'>" . self::getTypeName() . "</span>";
        echo "</div>";

        echo "<div class='card-body'>";

        echo "<div class='alert alert-info d-flex align-items-start mb-3'>";
        echo PluginTregopluginsIcon::html('info', 18, 'me-2 mt-1 flex-shrink-0');
        echo "<div>" . __('Restaura as restrições de visibilidade da Base de Conhecimento por Grupo/Perfil/Usuário, que são ignoradas por um bug do GLPI. Desative apenas para depuração.', 'tregoplugins') . "</div>";
        echo "</div>";

        echo "<div class='mb-1 form-check form-switch'>";
        echo "<input type='hidden' name='enabled' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='tregoplugins_kb_enabled' name='enabled' value='1'"
            . ($config['enabled'] ? " checked" : "") . ($canedit ? "" : " disabled") . ">";
        echo "<label class='form-check-label' for='tregoplugins_kb_enabled'>"
            . __('Ativar correção de visibilidade da Base de Conhecimento', 'tregoplugins') . "</label>";
        echo "</div>";

        echo "</div>"; // card-body

        if ($canedit) {
            echo "<div class='card-footer text-end'>";
            echo Html::hidden('id', ['value' => self::CONFIG_ID]);
            echo "<button type='submit' name='update' class='btn btn-primary'>"
                . PluginTregopluginsIcon::html('save', 16, 'me-1')
                . _sx('button', 'Save') . "</button>";
            echo "</div>";
        }

        Html::closeForm();
        echo "</div>"; // card
    }
}
