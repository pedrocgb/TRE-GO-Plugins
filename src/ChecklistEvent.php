<?php

/**
 * Append-only audit trail for the task-checklist module. Never updated or
 * purged by user action; rows are only removed transitively when their
 * owning TicketTask is purged (PluginTregopluginsChecklistInstantiator's
 * cleanup on Hooks::ITEM_PURGE). metadata_json must stay minimal and
 * non-sensitive (no tokens, no file content, no session data).
 */
class PluginTregopluginsChecklistEvent extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_tregoplugins_checklistevents';

    public const ACTION_CREATED_FROM_TEMPLATE = 'created_from_template';
    public const ACTION_ITEM_CHECKED = 'item_checked';
    public const ACTION_ITEM_REOPENED = 'item_reopened';
    public const ACTION_TASK_AUTO_COMPLETED = 'task_auto_completed';
    public const ACTION_TASK_COMPLETION_BLOCKED = 'task_completion_blocked';

    public const ORIGIN_UI = 'ui';
    public const ORIGIN_HOOK = 'hook';
    public const ORIGIN_SYSTEM = 'system';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Evento de checklist', 'Eventos de checklist', $nb, 'tregoplugins');
    }

    /**
     * @param array<string, mixed> $metadata minimal, non-sensitive extra context
     */
    public static function log(
        int $taskchecklists_id,
        ?int $taskchecklistitems_id,
        int $tickets_id,
        int $tickettasks_id,
        string $action,
        ?string $old_state,
        ?string $new_state,
        string $origin,
        array $metadata = []
    ): void {
        global $DB;

        self::migrateSchema();

        $DB->insert(self::TABLE, [
            'taskchecklists_id'     => $taskchecklists_id,
            'taskchecklistitems_id' => $taskchecklistitems_id ?? 0,
            'tickets_id'            => $tickets_id,
            'tickettasks_id'        => $tickettasks_id,
            'users_id'              => Session::getLoginUserID() ?: 0,
            'action'                => $action,
            'old_state'             => $old_state ?? '',
            'new_state'             => $new_state ?? '',
            'origin'                => $origin,
            'metadata_json'         => empty($metadata) ? '' : json_encode($metadata),
            'date_creation'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                     int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `taskchecklists_id`      int {$default_key_sign} NOT NULL,
                `taskchecklistitems_id`  int {$default_key_sign} NOT NULL DEFAULT 0,
                `tickets_id`             bigint unsigned NOT NULL,
                `tickettasks_id`         int {$default_key_sign} NOT NULL,
                `users_id`               int {$default_key_sign} NOT NULL DEFAULT 0,
                `action`                 varchar(64) NOT NULL DEFAULT '',
                `old_state`              varchar(64) NOT NULL DEFAULT '',
                `new_state`              varchar(64) NOT NULL DEFAULT '',
                `origin`                 varchar(16) NOT NULL DEFAULT 'ui',
                `metadata_json`          text,
                `date_creation`          timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `taskchecklists_id_date` (`taskchecklists_id`, `date_creation`)
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

    /**
     * Self-heals installs whose `tickets_id` column predates the switch to
     * bigint unsigned. A ticket id past the signed/unsigned int range makes
     * every insert into this table fail at the DB layer ("Out of range
     * value"); DBmysql::insert() logs that to the SQL log but doesn't throw,
     * so it never surfaces as a PHP exception here. Checked lazily on write
     * (like PluginTregopluginsOlaReportRepository::migrateSchema()) rather
     * than only at plugin update, so an existing install self-repairs
     * without requiring a manual Setup > Plugins > Update.
     */
    private static function migrateSchema(): void
    {
        global $DB;

        foreach ($DB->listFields(self::TABLE) as $name => $field) {
            if ($name !== 'tickets_id') {
                continue;
            }

            $type = strtolower((string) ($field['Type'] ?? ''));
            if (strpos($type, 'bigint') !== false && strpos($type, 'unsigned') !== false) {
                return;
            }
            break;
        }

        $migration = new Migration(PLUGIN_TREGOPLUGINS_VERSION);
        $migration->changeField(self::TABLE, 'tickets_id', 'tickets_id', 'bigint unsigned NOT NULL');
        $migration->executeMigration();
    }
}
