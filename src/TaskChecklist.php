<?php

/**
 * A snapshot of a PluginTregopluginsChecklistTemplate materialized onto one
 * TicketTask (at most one per task, enforced by a unique index on
 * tickettasks_id). Created only by PluginTregopluginsChecklistInstantiator;
 * never re-synchronized from the template after creation.
 */
class PluginTregopluginsTaskChecklist extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_tregoplugins_taskchecklists';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Checklist da tarefa', 'Checklists das tarefas', $nb, 'tregoplugins');
    }

    /**
     * Object-level authorization: a checklist is visible only through its
     * parent TicketTask/Ticket visibility, never through its own right
     * alone (a private task's checklist must not leak).
     */
    public function isVisibleToCurrentUser(): bool
    {
        if (!PluginTregopluginsChecklistProfile::canViewTaskChecklist()) {
            return false;
        }

        $task = new TicketTask();
        if (!$task->getFromDB((int) $this->fields['tickettasks_id'])) {
            return false;
        }

        return $task->canViewItem();
    }

    public function isUpdatableByCurrentUser(): bool
    {
        if (!PluginTregopluginsChecklistProfile::canUpdateTaskChecklist()) {
            return false;
        }

        $task = new TicketTask();
        if (!$task->getFromDB((int) $this->fields['tickettasks_id'])) {
            return false;
        }

        return $task->canViewItem();
    }

    public static function getForTask(int $tickettasks_id): ?self
    {
        $checklist = new self();
        if ($checklist->getFromDBByCrit(['tickettasks_id' => $tickettasks_id])) {
            return $checklist;
        }

        return null;
    }

    /**
     * Batch-load checklists for several tasks at once (used by the timeline
     * presenter, to avoid one query per task card).
     *
     * @param int[] $tickettasks_ids
     * @return array<int, array<string, mixed>> keyed by tickettasks_id
     */
    public static function getForTasks(array $tickettasks_ids): array
    {
        global $DB;

        $tickettasks_ids = array_values(array_filter(array_map('intval', $tickettasks_ids)));
        if (empty($tickettasks_ids)) {
            return [];
        }

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickettasks_id' => $tickettasks_ids],
        ]);

        $result = [];
        foreach ($iterator as $row) {
            $result[(int) $row['tickettasks_id']] = $row;
        }

        return $result;
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `tickettasks_id`                int {$default_key_sign} NOT NULL,
                `tickets_id`                    bigint unsigned NOT NULL,
                `source_checklisttemplates_id`  int {$default_key_sign} NOT NULL DEFAULT 0,
                `source_version`                int NOT NULL DEFAULT 1,
                `name_snapshot`                 varchar(255) NOT NULL DEFAULT '',
                `completion_policy_snapshot`    varchar(32) NOT NULL DEFAULT 'track_only',
                `created_by`                    int {$default_key_sign} NOT NULL DEFAULT 0,
                `date_creation`                 timestamp NULL DEFAULT NULL,
                `date_mod`                      timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tickettasks_id` (`tickettasks_id`),
                KEY `tickets_id` (`tickets_id`)
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
     * so PluginTregopluginsChecklistInstantiator::materialize()'s try/catch
     * never sees it and the task just silently ends up with no checklist.
     * Checked lazily on write (like
     * PluginTregopluginsOlaReportRepository::migrateSchema()) rather than
     * only at plugin update, so an existing install self-repairs without
     * requiring a manual Setup > Plugins > Update.
     */
    public static function migrateSchema(): void
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
