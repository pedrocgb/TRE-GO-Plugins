<?php

/**
 * Category-level storage for plugin settings.
 */
class PluginTregopluginsCategoryConfig
{
    public const TABLE = 'glpi_plugin_tregoplugins_itilcategoryconfigs';
    public const LEGACY_FORM_FIELD = 'plugin_tregoplugins_solutiontemplates_id';
    public const FORM_FIELD_REQUEST = 'plugin_tregoplugins_solutiontemplates_id_request';
    public const FORM_FIELD_INCIDENT = 'plugin_tregoplugins_solutiontemplates_id_incident';
    public const FORM_FIELD_AUTO_LINK_KB = 'plugin_tregoplugins_auto_link_knowbase';

    private const LEGACY_DB_FIELD = 'solutiontemplates_id';
    private const DB_FIELD_AUTO_LINK_KB = 'auto_link_knowbase';

    private static bool $schema_checked = false;

    public static function install(): bool
    {
        self::ensureSchema();
        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQueryOrDie(
                "DROP TABLE IF EXISTS `" . self::TABLE . "`",
                'Drop plugin tregoplugins category configuration table'
            );
        }

        return true;
    }

    public static function saveFromCategory(CommonDBTM $item): void
    {
        self::ensureSchema();

        if (!$item instanceof ITILCategory || !is_array($item->input)) {
            return;
        }

        $category_id = (int) $item->getID();
        if ($category_id <= 0) {
            return;
        }

        $request_template_id = self::normalizeTemplateId(
            $item->input[self::FORM_FIELD_REQUEST] ?? $item->input[self::LEGACY_FORM_FIELD] ?? 0
        );
        $incident_template_id = self::normalizeTemplateId(
            $item->input[self::FORM_FIELD_INCIDENT] ?? $item->input[self::LEGACY_FORM_FIELD] ?? 0
        );
        $auto_link_knowbase = self::normalizeBoolean(
            $item->input[self::FORM_FIELD_AUTO_LINK_KB] ?? self::getDefaultSettings()['auto_link_knowbase']
        );

        if ($request_template_id > 0 && !self::solutionTemplateExists($request_template_id)) {
            $request_template_id = 0;
        }

        if ($incident_template_id > 0 && !self::solutionTemplateExists($incident_template_id)) {
            $incident_template_id = 0;
        }

        if (
            $request_template_id <= 0
            && $incident_template_id <= 0
            && $auto_link_knowbase === self::getDefaultSettings()['auto_link_knowbase']
        ) {
            self::deleteForCategoryId($category_id);
            return;
        }

        self::persist(
            $category_id,
            $request_template_id,
            $incident_template_id,
            $auto_link_knowbase
        );
    }

    public static function deleteForCategory(CommonDBTM $item): void
    {
        if (!$item instanceof ITILCategory) {
            return;
        }

        self::deleteForCategoryId((int) $item->getID());
    }

    public static function getSolutionTemplateIdsForCategory(int $category_id): array
    {
        $settings = self::getCategorySettings($category_id);

        return [
            'request'  => $settings['request'],
            'incident' => $settings['incident'],
        ];
    }

    public static function getCategorySettings(int $category_id): array
    {
        global $DB;

        self::ensureSchema();

        $defaults = self::getDefaultSettings();
        if ($category_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return $defaults;
        }

        $iterator = $DB->request([
            'SELECT' => [
                'solutiontemplates_id_request',
                'solutiontemplates_id_incident',
                self::DB_FIELD_AUTO_LINK_KB,
            ],
            'FROM'   => self::TABLE,
            'WHERE'  => ['itilcategories_id' => $category_id],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return $defaults;
        }

        $row = $iterator->current();

        return [
            'request'            => (int) ($row['solutiontemplates_id_request'] ?? 0),
            'incident'           => (int) ($row['solutiontemplates_id_incident'] ?? 0),
            'auto_link_knowbase' => self::normalizeBoolean(
                $row[self::DB_FIELD_AUTO_LINK_KB] ?? $defaults['auto_link_knowbase']
            ),
        ];
    }

    public static function getSolutionTemplateIdForTicket(Ticket $ticket): int
    {
        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($category_id <= 0) {
            return 0;
        }

        $ids = self::getSolutionTemplateIdsForCategory($category_id);

        return match ((int) ($ticket->fields['type'] ?? 0)) {
            Ticket::DEMAND_TYPE   => $ids['request'],
            Ticket::INCIDENT_TYPE => $ids['incident'],
            default               => 0,
        };
    }

    public static function shouldAutoLinkKnowbase(Ticket $ticket): bool
    {
        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($category_id <= 0) {
            return false;
        }

        $settings = self::getCategorySettings($category_id);

        return $settings['auto_link_knowbase'];
    }

    private static function deleteForCategoryId(int $category_id): void
    {
        global $DB;

        self::ensureSchema();

        if ($category_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return;
        }

        $DB->delete(self::TABLE, ['itilcategories_id' => $category_id]);
    }

    private static function persist(
        int $category_id,
        int $request_template_id,
        int $incident_template_id,
        bool $auto_link_knowbase
    ): void {
        global $DB;

        if ($category_id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $existing = countElementsInTable(
            self::TABLE,
            ['itilcategories_id' => $category_id]
        ) > 0;

        $values = [
            'solutiontemplates_id_request'  => $request_template_id,
            'solutiontemplates_id_incident' => $incident_template_id,
            self::DB_FIELD_AUTO_LINK_KB     => (int) $auto_link_knowbase,
            'date_mod'                      => $now,
        ];

        if (!$existing) {
            $values['itilcategories_id'] = $category_id;
            $values['date_creation'] = $now;
        }

        $DB->updateOrInsert(
            self::TABLE,
            $values,
            ['itilcategories_id' => $category_id]
        );
    }

    private static function normalizeTemplateId(mixed $value): int
    {
        if (is_array($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
        }

        return (int) $value > 0;
    }

    private static function solutionTemplateExists(int $solution_template_id): bool
    {
        if ($solution_template_id <= 0) {
            return false;
        }

        $template = new SolutionTemplate();

        return $template->getFromDB($solution_template_id);
    }

    private static function ensureSchema(): void
    {
        global $DB;

        if (self::$schema_checked) {
            return;
        }

        self::$schema_checked = true;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists(self::TABLE)) {
            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `itilcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `solutiontemplates_id_request` int {$default_key_sign} NOT NULL DEFAULT '0',
                `solutiontemplates_id_incident` int {$default_key_sign} NOT NULL DEFAULT '0',
                `" . self::DB_FIELD_AUTO_LINK_KB . "` tinyint NOT NULL DEFAULT '1',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_itilcategories_id` (`itilcategories_id`),
                KEY `solutiontemplates_id_request` (`solutiontemplates_id_request`),
                KEY `solutiontemplates_id_incident` (`solutiontemplates_id_incident`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create plugin tregoplugins category configuration table');
            return;
        }

        if (!$DB->fieldExists(self::TABLE, 'solutiontemplates_id_request')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `solutiontemplates_id_request` int {$default_key_sign} NOT NULL DEFAULT '0'",
                'Add request solution template column'
            );
        }

        if (!$DB->fieldExists(self::TABLE, 'solutiontemplates_id_incident')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `solutiontemplates_id_incident` int {$default_key_sign} NOT NULL DEFAULT '0'",
                'Add incident solution template column'
            );
        }

        if (!$DB->fieldExists(self::TABLE, self::DB_FIELD_AUTO_LINK_KB)) {
            $DB->doQueryOrDie(
                "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `" . self::DB_FIELD_AUTO_LINK_KB . "` tinyint NOT NULL DEFAULT '1'",
                'Add knowledge base auto-link column'
            );
        }

        if ($DB->fieldExists(self::TABLE, self::LEGACY_DB_FIELD)) {
            $DB->doQueryOrDie(
                "UPDATE `" . self::TABLE . "`
                 SET `solutiontemplates_id_request` = CASE
                        WHEN `solutiontemplates_id_request` = 0 THEN `" . self::LEGACY_DB_FIELD . "`
                        ELSE `solutiontemplates_id_request`
                     END,
                     `solutiontemplates_id_incident` = CASE
                        WHEN `solutiontemplates_id_incident` = 0 THEN `" . self::LEGACY_DB_FIELD . "`
                        ELSE `solutiontemplates_id_incident`
                     END
                 WHERE `" . self::LEGACY_DB_FIELD . "` > 0",
                'Migrate legacy solution template column'
            );
        }
    }

    private static function getDefaultSettings(): array
    {
        return [
            'request'            => 0,
            'incident'           => 0,
            'auto_link_knowbase' => true,
        ];
    }
}
