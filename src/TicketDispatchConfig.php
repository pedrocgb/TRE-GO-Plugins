<?php

/**
 * Plugin-owned single-row configuration for the ticket dispatch module.
 *
 * Adds a "Despacho de chamados" tab on Setup > General (Config) with a
 * toggle to enable the module and the default technician group every newly
 * created ticket must land in. Access is gated by its own profile right
 * (plugin_tregoplugins_ticketdispatch), independent from the dispatch
 * action right itself (plugin_tregoplugins_ticketdispatch_action).
 */
class PluginTregopluginsTicketDispatchConfig extends CommonDBTM
{
    public static $rightname = 'plugin_tregoplugins_ticketdispatch';

    public const TABLE = 'glpi_plugin_tregoplugins_ticketdispatchconfigs';

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
        return __('Despacho de chamados', 'tregoplugins');
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

    public static function getDefaultGroupId(): int
    {
        return (int) (self::getConfig()['groups_id'] ?? 0);
    }

    /**
     * Whether the module is enabled AND its configured default group is
     * still a valid, active, assignable group. Fails safe: any doubt means
     * "not runnable" rather than risking a partial/incorrect ticket write.
     */
    public static function isRunnable(): bool
    {
        return self::isEnabled() && self::isGroupAssignable(self::getDefaultGroupId());
    }

    public static function isGroupAssignable(int $groups_id): bool
    {
        if ($groups_id <= 0) {
            return false;
        }

        $group = new Group();
        if (!$group->getFromDB($groups_id)) {
            return false;
        }

        return (int) ($group->fields['is_assign'] ?? 0) === 1;
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
            self::$cache = ['id' => self::CONFIG_ID, 'enabled' => 0, 'groups_id' => 0];
        }

        return self::$cache;
    }

    public function prepareInputForUpdate($input)
    {
        $enabled = isset($input['enabled']) && (int) $input['enabled'] === 1;
        $groups_id = (int) ($input['groups_id'] ?? $this->fields['groups_id'] ?? 0);

        if ($enabled && !self::isGroupAssignable($groups_id)) {
            Session::addMessageAfterRedirect(
                __('Selecione uma unidade (grupo) ativa e atribuível antes de ativar o despacho de chamados.', 'tregoplugins'),
                false,
                ERROR
            );
            return false;
        }

        $input['enabled'] = $enabled ? 1 : 0;
        $input['groups_id'] = $groups_id;

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
                `id`         int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `enabled`    tinyint NOT NULL DEFAULT 0,
                `groups_id`  int {$default_key_sign} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
            $DB->insert(self::TABLE, ['id' => self::CONFIG_ID, 'enabled' => 0, 'groups_id' => 0]);
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

        echo "<div class='center'>";
        echo "<form name='form' method='post' action='" . Plugin::getWebDir('tregoplugins') . "/front/ticketdispatch.config.form.php'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ativar módulo de despacho de chamados', 'tregoplugins') . "</td>";
        echo "<td>";
        if ($canedit) {
            Dropdown::showYesNo('enabled', $config['enabled']);
        } else {
            echo $config['enabled'] ? __('Yes') : __('No');
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Unidade Padrão dos chamados criados', 'tregoplugins') . "</td>";
        echo "<td>";
        if ($canedit) {
            Group::dropdown([
                'name'      => 'groups_id',
                'value'     => $config['groups_id'],
                'condition' => ['is_assign' => 1],
                'entity'    => $_SESSION['glpiactive_entity'] ?? 0,
                'entity_sons' => true,
            ]);
        } else {
            echo Dropdown::getDropdownName(Group::getTable(), $config['groups_id']);
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td colspan='2'>" . __('Chamados criados por qualquer via (interface, e-mail, API) são movidos para esta unidade após as regras de negócio normais serem executadas. O botão "Despachar chamado para Unidade Responsável" permite reaplicar essas regras manualmente depois.', 'tregoplugins') . "</td>";
        echo "</tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_1'>";
            echo "<td colspan='2' class='center'>";
            echo Html::hidden('id', ['value' => self::CONFIG_ID]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</td></tr>";
        }
        echo "</table>";
        Html::closeForm();
        echo "</div>";
    }
}
