<?php

/**
 * One snapshotted, markable item of a PluginTregopluginsTaskChecklist. Text,
 * order and requirement flags are copied at materialization time and never
 * re-synchronized from the template. `lock_version` guards concurrent
 * toggles (optimistic concurrency): an update is conditioned on the row
 * still being at the expected version.
 */
class PluginTregopluginsTaskChecklistItem extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_tregoplugins_taskchecklistitems';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Item de checklist', 'Itens de checklist', $nb, 'tregoplugins');
    }

    /**
     * @return array<int, array<string, mixed>> active items ordered by snapshot position
     */
    public static function getForChecklist(int $taskchecklists_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['taskchecklists_id' => $taskchecklists_id, 'is_active' => 1],
            'ORDER' => 'position_snapshot ASC',
        ]);

        return iterator_to_array($iterator, false);
    }

    /**
     * @param int[] $taskchecklists_ids
     * @return array<int, array<int, array<string, mixed>>> keyed by taskchecklists_id
     */
    public static function getForChecklists(array $taskchecklists_ids): array
    {
        global $DB;

        $taskchecklists_ids = array_values(array_filter(array_map('intval', $taskchecklists_ids)));
        if (empty($taskchecklists_ids)) {
            return [];
        }

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['taskchecklists_id' => $taskchecklists_ids, 'is_active' => 1],
            'ORDER' => 'taskchecklists_id ASC, position_snapshot ASC',
        ]);

        $result = [];
        foreach ($iterator as $row) {
            $result[(int) $row['taskchecklists_id']][] = $row;
        }

        return $result;
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                       int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `taskchecklists_id`        int {$default_key_sign} NOT NULL,
                `source_templateitems_id`  int {$default_key_sign} NOT NULL DEFAULT 0,
                `name_snapshot`            varchar(255) NOT NULL DEFAULT '',
                `instructions_snapshot`    text,
                `position_snapshot`        int NOT NULL DEFAULT 0,
                `is_required_snapshot`     tinyint NOT NULL DEFAULT 0,
                `is_active`                tinyint NOT NULL DEFAULT 1,
                `is_checked`               tinyint NOT NULL DEFAULT 0,
                `checked_by`               int {$default_key_sign} NOT NULL DEFAULT 0,
                `checked_at`               timestamp NULL DEFAULT NULL,
                `note`                     text,
                `lock_version`             int NOT NULL DEFAULT 1,
                `date_creation`            timestamp NULL DEFAULT NULL,
                `date_mod`                 timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `taskchecklists_id_position` (`taskchecklists_id`, `position_snapshot`),
                KEY `taskchecklists_id_pending_required` (`taskchecklists_id`, `is_active`, `is_required_snapshot`, `is_checked`)
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
