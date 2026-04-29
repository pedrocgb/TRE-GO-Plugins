<?php

/**
 * Shared OLA TTO calendar helpers.
 */
class PluginTregopluginsOlaBusinessTimeService
{
    public const GROUP_CALENDAR_TABLE = 'glpi_plugin_tregoplugins_group_calendars';

    public static function install(): void
    {
        self::ensureSchema();
    }

    public static function uninstall(): void
    {
        global $DB;

        if ($DB->tableExists(self::GROUP_CALENDAR_TABLE)) {
            $DB->doQueryOrDie(
                'DROP TABLE IF EXISTS `' . self::GROUP_CALENDAR_TABLE . '`',
                'Drop plugin tregoplugins group calendar table'
            );
        }
    }

    public static function ensureSchema(): void
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        if ($DB->tableExists(self::GROUP_CALENDAR_TABLE)) {
            return;
        }

        $DB->doQueryOrDie(
            "CREATE TABLE `" . self::GROUP_CALENDAR_TABLE . "` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `groups_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `calendars_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_groups_id` (`groups_id`),
                KEY `idx_calendars_id` (`calendars_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
            'Create plugin tregoplugins group calendar table'
        );
    }

    public static function getOlaDurationSeconds(Ticket $ticket): int
    {
        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id <= 0) {
            return 0;
        }

        $ola = new OLA();
        if (!$ola->getFromDB($ola_id)) {
            return 0;
        }

        return max(0, (int) $ola->getTime());
    }

    public static function computeDueDate(Ticket $ticket, string $start_date, int $group_id = 0): ?string
    {
        $duration = self::getOlaDurationSeconds($ticket);
        if ($duration <= 0) {
            return null;
        }

        $calendar = self::getCalendarForTicketGroup($ticket, $group_id);
        if ($calendar instanceof Calendar) {
            $due_date = $calendar->computeEndDate($start_date, $duration);
            return is_string($due_date) && $due_date !== '' ? self::normalizeDatetime($due_date) : null;
        }

        return self::normalizeDatetime(date('Y-m-d H:i:s', strtotime($start_date) + $duration));
    }

    public static function getActiveTimeBetween(
        Ticket $ticket,
        string $start_date,
        string $end_date,
        int $group_id = 0
    ): int {
        $start = strtotime($start_date);
        $end = strtotime($end_date);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        $calendar = self::getCalendarForTicketGroup($ticket, $group_id);
        if ($calendar instanceof Calendar) {
            return max(0, (int) $calendar->getActiveTimeBetween($start_date, $end_date));
        }

        return max(0, $end - $start);
    }

    public static function getCalendarIdForTicketGroup(Ticket $ticket, int $group_id = 0): int
    {
        if ($group_id <= 0) {
            $group_id = self::getCurrentAssignedGroupId((int) $ticket->getID());
        }

        $group_calendar_id = self::getGroupCalendarId($group_id);
        if ($group_calendar_id > 0) {
            return $group_calendar_id;
        }

        $ticket_calendar_id = (int) ($ticket->getCalendar(SLM::TTO) ?? 0);
        if ($ticket_calendar_id > 0) {
            return $ticket_calendar_id;
        }

        return (int) Entity::getUsedConfig(
            'calendars_strategy',
            (int) ($ticket->fields['entities_id'] ?? 0),
            'calendars_id',
            0
        );
    }

    public static function getGroupCalendarId(int $group_id): int
    {
        global $DB;

        self::ensureSchema();
        if ($group_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['calendars_id'],
            'FROM'   => self::GROUP_CALENDAR_TABLE,
            'WHERE'  => ['groups_id' => $group_id],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return 0;
        }

        $row = $iterator->current();
        return (int) ($row['calendars_id'] ?? 0);
    }

    public static function saveGroupCalendar(int $group_id, int $calendar_id): bool
    {
        global $DB;

        self::ensureSchema();
        if ($group_id <= 0) {
            return false;
        }

        if ($calendar_id <= 0) {
            $DB->delete(self::GROUP_CALENDAR_TABLE, ['groups_id' => $group_id]);
            return true;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        return (bool) $DB->updateOrInsert(
            self::GROUP_CALENDAR_TABLE,
            [
                'groups_id'      => $group_id,
                'calendars_id'   => $calendar_id,
                'date_mod'       => $now,
                'date_creation'  => $now,
            ],
            ['groups_id' => $group_id]
        );
    }

    public static function getCalendarForTicketGroup(Ticket $ticket, int $group_id = 0): ?Calendar
    {
        static $calendar_cache = [];

        $calendar_id = self::getCalendarIdForTicketGroup($ticket, $group_id);
        if ($calendar_id <= 0) {
            return null;
        }

        if (array_key_exists($calendar_id, $calendar_cache)) {
            return $calendar_cache[$calendar_id];
        }

        $calendar = new Calendar();
        if (!$calendar->getFromDB($calendar_id)) {
            $calendar_cache[$calendar_id] = null;
            return null;
        }

        $calendar_cache[$calendar_id] = $calendar;
        return $calendar;
    }

    public static function getCurrentAssignedGroupId(int $ticket_id): int
    {
        global $DB;

        if ($ticket_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => 'glpi_groups_tickets',
            'WHERE'  => [
                'tickets_id' => $ticket_id,
                'type'       => CommonITILActor::ASSIGN,
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return 0;
        }

        $row = $iterator->current();
        return (int) ($row['groups_id'] ?? 0);
    }

    public static function normalizeDatetime(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
