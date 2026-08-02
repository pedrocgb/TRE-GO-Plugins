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

    public static function getInitialRuleId(): int
    {
        return (int) (self::getConfig()['initial_rule_id'] ?? 0);
    }

    /**
     * Status forced on every newly created ticket when its technician group
     * gets overridden to the default. Defaults to Ticket::INCOMING ("New").
     */
    public static function getCreationStatus(): int
    {
        $status = (int) (self::getConfig()['creation_status'] ?? 0);

        return isset(Ticket::getAllStatusArray()[$status]) ? $status : Ticket::INCOMING;
    }

    /**
     * Whether the module is enabled AND its configured initial rule is still
     * a valid, active RuleTicket with an ONADD condition. Fails safe: any
     * doubt means "not runnable" rather than risking a partial/incorrect
     * ticket write.
     */
    public static function isRunnable(): bool
    {
        return self::isEnabled() && self::isRuleValid(self::getInitialRuleId());
    }

    public static function isRuleValid(int $rules_id): bool
    {
        if ($rules_id <= 0) {
            return false;
        }

        $rule = new RuleTicket();
        if (!$rule->getFromDB($rules_id)) {
            return false;
        }

        return (int) ($rule->fields['is_active'] ?? 0) === 1
            && ((int) ($rule->fields['condition'] ?? 0) & RuleTicket::ONADD) !== 0;
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
            self::$cache = ['id' => self::CONFIG_ID, 'enabled' => 0, 'initial_rule_id' => 0];
        }

        return self::$cache;
    }

    public function prepareInputForUpdate($input)
    {
        $enabled = isset($input['enabled']) && (int) $input['enabled'] === 1;
        $initial_rule_id = (int) ($input['initial_rule_id'] ?? $this->fields['initial_rule_id'] ?? 0);

        if ($enabled && !self::isRuleValid($initial_rule_id)) {
            Session::addMessageAfterRedirect(
                __('Selecione uma Regra de Atendimento Inicial válida e ativa antes de ativar o despacho de chamados.', 'tregoplugins'),
                false,
                ERROR
            );
            return false;
        }

        $creation_status = (int) ($input['creation_status'] ?? $this->fields['creation_status'] ?? Ticket::INCOMING);
        if (!isset(Ticket::getAllStatusArray()[$creation_status])) {
            $creation_status = Ticket::INCOMING;
        }

        $input['enabled'] = $enabled ? 1 : 0;
        $input['initial_rule_id'] = $initial_rule_id;
        $input['creation_status'] = $creation_status;

        return $input;
    }

    public function post_updateItem($history = true)
    {
        self::$cache = null;
    }

    public static function install(): bool
    {
        global $DB;

        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists(self::TABLE)) {
            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`               int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `enabled`          tinyint NOT NULL DEFAULT 0,
                `initial_rule_id`  int {$default_key_sign} NOT NULL DEFAULT 0,
                `creation_status`  int NOT NULL DEFAULT " . Ticket::INCOMING . ",
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
            $DB->insert(self::TABLE, [
                'id'              => self::CONFIG_ID,
                'enabled'         => 0,
                'initial_rule_id' => 0,
                'creation_status' => Ticket::INCOMING,
            ]);
        } else {
            if (!$DB->fieldExists(self::TABLE, 'creation_status')) {
                $DB->doQueryOrDie(
                    "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `creation_status` int NOT NULL DEFAULT " . Ticket::INCOMING,
                    'Add creation_status to ' . self::TABLE
                );
            }

            if (!$DB->fieldExists(self::TABLE, 'initial_rule_id')) {
                $DB->doQueryOrDie(
                    "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `initial_rule_id` int {$default_key_sign} NOT NULL DEFAULT 0",
                    'Add initial_rule_id to ' . self::TABLE
                );
            }

            if ($DB->fieldExists(self::TABLE, 'groups_id')) {
                $DB->doQueryOrDie(
                    "ALTER TABLE `" . self::TABLE . "` DROP COLUMN `groups_id`",
                    'Drop groups_id from ' . self::TABLE
                );
            }
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

        echo "<div class='card mb-3'>";
        echo "<form method='post' action='" . Plugin::getWebDir('tregoplugins') . "/front/ticketdispatch.config.form.php'>";

        echo "<div class='card-header d-flex align-items-center'>";
        echo PluginTregopluginsIcon::html('forward', 20, 'me-2');
        echo "<span class='card-title mb-0'>" . self::getTypeName() . "</span>";
        echo "</div>";

        echo "<div class='card-body'>";

        echo "<div class='alert alert-info d-flex align-items-start mb-3'>";
        echo PluginTregopluginsIcon::html('info', 18, 'me-2 mt-1 flex-shrink-0');
        echo "<div>" . __('Chamados criados por qualquer via (interface, e-mail, API) seguem primeiro a Regra de Atendimento Inicial abaixo, ignorando as demais regras de negócio de chamados. O botão "Despachar Chamado" aplica depois, manualmente, o conjunto completo de regras normalmente configurado.', 'tregoplugins') . "</div>";
        echo "</div>";

        echo "<div class='mb-3 form-check form-switch'>";
        echo "<input type='hidden' name='enabled' value='0'>";
        echo "<input type='checkbox' class='form-check-input' id='tregoplugins_td_enabled' name='enabled' value='1'"
            . ($config['enabled'] ? " checked" : "") . ($canedit ? "" : " disabled") . ">";
        echo "<label class='form-check-label' for='tregoplugins_td_enabled'>"
            . __('Ativar módulo de despacho de chamados', 'tregoplugins') . "</label>";
        echo "</div>";

        echo "<div class='mb-3'>";
        echo "<label class='form-label d-flex align-items-center' for='dropdown_initial_rule_id'>"
            . PluginTregopluginsIcon::html('forward', 16, 'me-1')
            . __('Regra de Atendimento Inicial', 'tregoplugins') . "</label>";
        if ($canedit) {
            // Rule::dropdown() only filters by sub_type/condition, not
            // is_active: GLPI ships several inactive ONADD RuleTicket rules
            // out of the box, and picking one here would silently fail
            // isRuleValid() on save (the field reverts to empty with just a
            // flash message). Filter is_active here so every listed choice
            // is actually savable.
            Dropdown::show('RuleTicket', [
                'name'      => 'initial_rule_id',
                'value'     => $config['initial_rule_id'],
                'entity'    => $_SESSION['glpiactive_entity'] ?? 0,
                'condition' => [
                    'is_active' => 1,
                    'condition' => ['&', RuleTicket::ONADD],
                ],
            ]);
        } else {
            echo "<div>" . Dropdown::getDropdownName(RuleTicket::getTable(), $config['initial_rule_id']) . "</div>";
        }
        echo "</div>";

        echo "<div class='mb-3'>";
        echo "<label class='form-label d-flex align-items-center' for='dropdown_creation_status'>"
            . PluginTregopluginsIcon::html('circle-dot', 16, 'me-1')
            . __('Status inicial dos chamados criados', 'tregoplugins') . "</label>";
        if ($canedit) {
            Ticket::dropdownStatus(['value' => $config['creation_status'] ?? Ticket::INCOMING, 'name' => 'creation_status']);
        } else {
            echo "<div>" . Ticket::getStatus((int) ($config['creation_status'] ?? Ticket::INCOMING)) . "</div>";
        }
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
