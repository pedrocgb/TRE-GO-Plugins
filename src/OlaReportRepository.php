<?php

class PluginTregopluginsOlaReportRepository
{
    public const TABLE = 'glpi_plugin_tregoplugins_ola_group_passes';

    public static function install(): void
    {
        self::ensureSchema();
        self::seedCurrentOpenPasses();
    }

    public static function uninstall(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQueryOrDie(
                'DROP TABLE IF EXISTS `' . self::TABLE . '`',
                'Drop plugin tregoplugins OLA report history table'
            );
        }
    }

    public static function ensureSchema(): void
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

        $DB->doQueryOrDie(
            "CREATE TABLE `" . self::TABLE . "` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `ticket_title` varchar(255) NOT NULL DEFAULT '',
                `ticket_status` int NOT NULL DEFAULT '0',
                `ticket_status_label` varchar(128) NOT NULL DEFAULT '',
                `ticket_opened_at` timestamp NULL DEFAULT NULL,
                `itilcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `category_name` varchar(255) NOT NULL DEFAULT '',
                `requester_name` text DEFAULT NULL,
                `groups_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `group_name` varchar(255) NOT NULL DEFAULT '',
                `escalated_from_groups_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `escalated_from_group_name` varchar(255) NOT NULL DEFAULT '',
                `calendars_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `pass_started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ola_due_at` timestamp NULL DEFAULT NULL,
                `assigned_at` timestamp NULL DEFAULT NULL,
                `users_id_assign` int {$default_key_sign} NOT NULL DEFAULT '0',
                `technician_name` varchar(255) NOT NULL DEFAULT '',
                `working_seconds_to_assignment` int unsigned DEFAULT NULL,
                `ola_exceeded` tinyint(1) DEFAULT NULL,
                `pass_ended_at` timestamp NULL DEFAULT NULL,
                `is_open` tinyint(1) NOT NULL DEFAULT '1',
                `close_reason` varchar(64) NOT NULL DEFAULT 'open',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ticket_open` (`tickets_id`, `is_open`, `pass_started_at`),
                KEY `idx_group_started` (`groups_id`, `pass_started_at`),
                KEY `idx_group_period` (`groups_id`, `pass_started_at`, `pass_ended_at`),
                KEY `idx_calendar` (`calendars_id`),
                KEY `idx_assigned` (`assigned_at`, `ola_exceeded`),
                KEY `idx_ticket` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC",
            'Create plugin tregoplugins OLA report history table'
        );
    }

    public static function handleTicketCreated(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket) {
            return;
        }

        $group_id = PluginTregopluginsOlaBusinessTimeService::getCurrentAssignedGroupId((int) $item->getID());
        if ($group_id <= 0) {
            return;
        }

        self::startGroupPass($item, $group_id, self::currentDatetime(), 0);
    }

    public static function handleGroupAssignment(CommonDBTM $item): void
    {
        if (!$item instanceof Group_Ticket) {
            return;
        }

        if ((int) ($item->fields['type'] ?? $item->input['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticket = self::getTicketFromActorLink($item);
        $group_id = (int) ($item->fields['groups_id'] ?? $item->input['groups_id'] ?? 0);
        if ($ticket === null || $group_id <= 0) {
            return;
        }

        self::startGroupPass($ticket, $group_id, self::currentDatetime(), self::getOpenGroupId((int) $ticket->getID()));
    }

    public static function handleGroupChange(CommonDBTM $item): void
    {
        if (!$item instanceof Group_Ticket) {
            return;
        }

        $updates = (array) ($item->updates ?? []);
        if (!in_array('groups_id', $updates, true) && !in_array('type', $updates, true)) {
            return;
        }

        self::handleGroupAssignment($item);
    }

    public static function handleTechnicianAssignment(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket_User && !$item instanceof Supplier_Ticket) {
            return;
        }

        if ((int) ($item->fields['type'] ?? $item->input['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticket = self::getTicketFromActorLink($item);
        if ($ticket === null) {
            return;
        }

        $user_id = $item instanceof Ticket_User ? (int) ($item->fields['users_id'] ?? 0) : 0;
        $supplier_id = $item instanceof Supplier_Ticket ? (int) ($item->fields['suppliers_id'] ?? 0) : 0;
        self::assignOpenPass($ticket, $user_id, $supplier_id, self::currentDatetime());
    }

    public static function handleTicketUpdated(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket) {
            return;
        }

        self::refreshOpenPassSnapshot($item);
        self::refreshOpenPassOlaTiming($item);

        if (in_array((int) ($item->fields['status'] ?? 0), [Ticket::SOLVED, Ticket::CLOSED], true)) {
            $open = self::getOpenPass((int) $item->getID());
            if ($open !== null && empty($open['assigned_at'])) {
                self::closeOpenPass((int) $open['id'], 'ticket_closed', self::currentDatetime(), null);
            }
        }
    }

    public static function getReportRows(int $group_id, string $date_from, string $date_to): array
    {
        global $DB;

        self::ensureSchema();
        if ($group_id <= 0) {
            return [];
        }

        self::syncCurrentPasses();

        $group_id = (int) $group_id;
        $date_from = $DB->escape($date_from);
        $date_to = $DB->escape($date_to);
        $iterator = $DB->request(
            "SELECT `p`.*
             FROM `" . self::TABLE . "` AS `p`
             INNER JOIN `glpi_tickets` AS `t`
                ON (`t`.`id` = `p`.`tickets_id`)
             WHERE `p`.`groups_id` = {$group_id}
               AND `t`.`is_deleted` = 0
               AND `p`.`pass_started_at` <= '{$date_to}'
               AND COALESCE(`p`.`pass_ended_at`, `p`.`assigned_at`, NOW()) >= '{$date_from}'
             ORDER BY `p`.`pass_started_at` ASC, `p`.`id` ASC"
        );

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getAvailableGroups(): array
    {
        global $DB;

        $groups = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'completename'],
            'FROM'   => Group::getTable(),
            'WHERE'  => ['is_assign' => 1],
            'ORDER'  => ['completename ASC', 'name ASC'],
        ]);

        foreach ($iterator as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $groups[] = ['id' => $id, 'name' => self::formatGroupName($row)];
            }
        }

        return $groups;
    }

    public static function getAvailableCalendars(): array
    {
        global $DB;

        $calendars = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => Calendar::getTable(),
            'ORDER'  => ['name ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0) {
                $calendars[] = ['id' => $id, 'name' => $name !== '' ? $name : 'Calendário #' . $id];
            }
        }

        return $calendars;
    }

    public static function getGroupName(int $group_id): string
    {
        global $DB;

        if ($group_id <= 0) {
            return '';
        }

        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'completename'],
            'FROM'   => Group::getTable(),
            'WHERE'  => ['id' => $group_id],
            'LIMIT'  => 1,
        ]);

        return count($iterator) > 0 ? self::formatGroupName($iterator->current()) : '';
    }

    public static function syncCurrentPasses(): void
    {
        self::seedCurrentPasses();
    }

    private static function seedCurrentOpenPasses(): void
    {
        self::seedCurrentPasses();
    }

    private static function seedCurrentPasses(): void
    {
        global $DB;

        self::ensureSchema();
        $iterator = $DB->request(
            "SELECT `gt`.`tickets_id`, `gt`.`groups_id`
             FROM `glpi_groups_tickets` AS `gt`
             INNER JOIN `glpi_tickets` AS `t`
                ON (`t`.`id` = `gt`.`tickets_id`)
             WHERE `gt`.`type` = " . (int) CommonITILActor::ASSIGN . "
               AND `t`.`is_deleted` = 0
             ORDER BY `gt`.`id` ASC"
        );

        $latest_group_by_ticket = [];
        foreach ($iterator as $row) {
            $ticket_id = (int) ($row['tickets_id'] ?? 0);
            $group_id = (int) ($row['groups_id'] ?? 0);
            if ($ticket_id > 0 && $group_id > 0) {
                $latest_group_by_ticket[$ticket_id] = $group_id;
            }
        }

        foreach ($latest_group_by_ticket as $ticket_id => $group_id) {
            if (self::getOpenPass($ticket_id) !== null) {
                continue;
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticket_id)) {
                continue;
            }

            $started_at = trim((string) ($ticket->fields['ola_tto_begin_date'] ?? ''));
            if ($started_at === '') {
                $started_at = trim((string) ($ticket->fields['date'] ?? self::currentDatetime()));
            }

            if (self::hasPassForStart($ticket_id, $group_id, $started_at)) {
                continue;
            }

            self::startGroupPass($ticket, $group_id, $started_at, 0);
            self::seedAssignmentIfPresent($ticket);
        }
    }

    private static function startGroupPass(Ticket $ticket, int $group_id, string $event_at, int $from_group_id): void
    {
        global $DB;

        self::ensureSchema();
        $ticket_id = (int) $ticket->getID();
        if ($ticket_id <= 0 || $group_id <= 0) {
            return;
        }

        $open = self::getOpenPass($ticket_id);
        if ($open !== null) {
            $open_group_id = (int) ($open['groups_id'] ?? 0);
            if ($open_group_id === $group_id) {
                self::refreshOpenPassSnapshot($ticket);
                return;
            }

            self::closeOpenPass((int) $open['id'], 'escalated', $event_at, null);
            if ($from_group_id <= 0) {
                $from_group_id = $open_group_id;
            }
        }

        $calendar_id = PluginTregopluginsOlaBusinessTimeService::getCalendarIdForTicketGroup($ticket, $group_id);
        $due_date = PluginTregopluginsOlaBusinessTimeService::computeDueDate($ticket, $event_at, $group_id);
        $snapshot = self::buildTicketSnapshot($ticket);
        $now = self::currentDatetime();

        $DB->insert(
            self::TABLE,
            [
                'tickets_id'                 => $ticket_id,
                'entities_id'                => (int) ($ticket->fields['entities_id'] ?? 0),
                'ticket_title'               => $snapshot['ticket_title'],
                'ticket_status'              => $snapshot['ticket_status'],
                'ticket_status_label'        => $snapshot['ticket_status_label'],
                'ticket_opened_at'           => $snapshot['ticket_opened_at'],
                'itilcategories_id'          => $snapshot['itilcategories_id'],
                'category_name'              => $snapshot['category_name'],
                'requester_name'             => $snapshot['requester_name'],
                'groups_id'                  => $group_id,
                'group_name'                 => self::getGroupName($group_id),
                'escalated_from_groups_id'   => $from_group_id,
                'escalated_from_group_name'  => self::getGroupName($from_group_id),
                'calendars_id'               => $calendar_id,
                'pass_started_at'            => $event_at,
                'ola_due_at'                 => $due_date,
                'is_open'                    => 1,
                'close_reason'               => 'open',
                'date_creation'              => $now,
                'date_mod'                   => $now,
            ]
        );
    }

    private static function seedAssignmentIfPresent(Ticket $ticket): void
    {
        $ticket_id = (int) $ticket->getID();
        if ($ticket_id <= 0 || self::getOpenPass($ticket_id) === null) {
            return;
        }

        $assigned_at = trim((string) ($ticket->fields['takeintoaccountdate'] ?? ''));
        if ($assigned_at === '') {
            $delay = (int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0);
            $start = trim((string) ($ticket->fields['ola_tto_begin_date'] ?? $ticket->fields['date'] ?? ''));
            if ($delay > 0 && $start !== '') {
                $assigned_at = date('Y-m-d H:i:s', strtotime($start) + $delay);
            }
        }

        $user_id = self::getCurrentAssignedUserId($ticket_id);
        $supplier_id = self::getCurrentAssignedSupplierId($ticket_id);
        if ($user_id <= 0 && $supplier_id <= 0 && $assigned_at === '') {
            return;
        }

        if ($assigned_at === '') {
            $assigned_at = self::currentDatetime();
        }

        self::assignOpenPass($ticket, $user_id, $supplier_id, $assigned_at);
    }

    private static function assignOpenPass(Ticket $ticket, int $user_id, int $supplier_id, string $event_at): void
    {
        global $DB;

        $open = self::getOpenPass((int) $ticket->getID());
        if ($open === null || !empty($open['assigned_at'])) {
            return;
        }

        $group_id = (int) ($open['groups_id'] ?? 0);
        $started_at = (string) ($open['pass_started_at'] ?? $event_at);
        $working_seconds = PluginTregopluginsOlaBusinessTimeService::getActiveTimeBetween(
            $ticket,
            $started_at,
            $event_at,
            $group_id
        );
        $duration = PluginTregopluginsOlaBusinessTimeService::getOlaDurationSeconds($ticket);
        $technician = $user_id > 0 ? self::getUserName($user_id) : self::getSupplierName($supplier_id);

        $DB->update(
            self::TABLE,
            [
                'assigned_at'                   => $event_at,
                'users_id_assign'               => $user_id,
                'technician_name'               => $technician,
                'working_seconds_to_assignment' => $working_seconds,
                'ola_exceeded'                  => $duration > 0 ? (int) ($working_seconds > $duration) : null,
                'pass_ended_at'                 => $event_at,
                'is_open'                       => 0,
                'close_reason'                  => 'assigned',
                'date_mod'                      => self::currentDatetime(),
            ],
            ['id' => (int) $open['id']]
        );
    }

    private static function closeOpenPass(int $pass_id, string $reason, string $event_at, ?int $ola_exceeded): void
    {
        global $DB;

        if ($pass_id <= 0) {
            return;
        }

        $DB->update(
            self::TABLE,
            [
                'pass_ended_at' => $event_at,
                'is_open'       => 0,
                'close_reason'  => $reason,
                'ola_exceeded'  => $ola_exceeded,
                'date_mod'      => self::currentDatetime(),
            ],
            ['id' => $pass_id]
        );
    }

    private static function refreshOpenPassSnapshot(Ticket $ticket): void
    {
        global $DB;

        $open = self::getOpenPass((int) $ticket->getID());
        if ($open === null) {
            return;
        }

        $snapshot = self::buildTicketSnapshot($ticket);
        $DB->update(
            self::TABLE,
            [
                'ticket_title'        => $snapshot['ticket_title'],
                'ticket_status'       => $snapshot['ticket_status'],
                'ticket_status_label' => $snapshot['ticket_status_label'],
                'itilcategories_id'   => $snapshot['itilcategories_id'],
                'category_name'       => $snapshot['category_name'],
                'requester_name'      => $snapshot['requester_name'],
                'date_mod'            => self::currentDatetime(),
            ],
            ['id' => (int) $open['id']]
        );
    }

    private static function refreshOpenPassOlaTiming(Ticket $ticket): void
    {
        global $DB;

        $updates = (array) ($ticket->updates ?? []);
        if (
            !in_array('olas_id_tto', $updates, true)
            && !in_array('ola_tto_begin_date', $updates, true)
            && !in_array('internal_time_to_own', $updates, true)
        ) {
            return;
        }

        $open = self::getOpenPass((int) $ticket->getID());
        if ($open === null) {
            return;
        }

        $group_id = (int) ($open['groups_id'] ?? 0);
        $started_at = (string) ($open['pass_started_at'] ?? self::currentDatetime());
        $DB->update(
            self::TABLE,
            [
                'calendars_id' => PluginTregopluginsOlaBusinessTimeService::getCalendarIdForTicketGroup($ticket, $group_id),
                'ola_due_at'   => PluginTregopluginsOlaBusinessTimeService::computeDueDate($ticket, $started_at, $group_id),
                'date_mod'     => self::currentDatetime(),
            ],
            ['id' => (int) $open['id']]
        );
    }

    private static function buildTicketSnapshot(Ticket $ticket): array
    {
        $status_id = (int) ($ticket->fields['status'] ?? 0);
        return [
            'ticket_title'        => (string) ($ticket->fields['name'] ?? ''),
            'ticket_status'       => $status_id,
            'ticket_status_label' => self::formatTicketStatus($status_id),
            'ticket_opened_at'    => $ticket->fields['date'] ?? null,
            'itilcategories_id'   => (int) ($ticket->fields['itilcategories_id'] ?? 0),
            'category_name'       => self::getCategoryName((int) ($ticket->fields['itilcategories_id'] ?? 0)),
            'requester_name'      => self::getRequesterNames((int) $ticket->getID()),
        ];
    }

    private static function getOpenPass(int $ticket_id): ?array
    {
        global $DB;

        if ($ticket_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticket_id, 'is_open' => 1],
            'ORDER' => ['pass_started_at DESC', 'id DESC'],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0 ? $iterator->current() : null;
    }

    private static function hasPassForStart(int $ticket_id, int $group_id, string $started_at): bool
    {
        global $DB;

        if ($ticket_id <= 0 || $group_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'tickets_id'       => $ticket_id,
                'groups_id'        => $group_id,
                'pass_started_at'  => $started_at,
            ],
            'LIMIT'  => 1,
        ]);

        return count($iterator) > 0;
    }

    private static function getOpenGroupId(int $ticket_id): int
    {
        $open = self::getOpenPass($ticket_id);
        return $open !== null ? (int) ($open['groups_id'] ?? 0) : 0;
    }

    private static function getTicketFromActorLink(CommonDBTM $item): ?Ticket
    {
        $ticket_id = (int) ($item->fields['tickets_id'] ?? $item->input['tickets_id'] ?? 0);
        $ticket = new Ticket();
        return $ticket_id > 0 && $ticket->getFromDB($ticket_id) ? $ticket : null;
    }

    private static function getCurrentAssignedUserId(int $ticket_id): int
    {
        global $DB;

        if ($ticket_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_tickets_users',
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
        return (int) ($row['users_id'] ?? 0);
    }

    private static function getCurrentAssignedSupplierId(int $ticket_id): int
    {
        global $DB;

        if ($ticket_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['suppliers_id'],
            'FROM'   => 'glpi_suppliers_tickets',
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
        return (int) ($row['suppliers_id'] ?? 0);
    }

    private static function getRequesterNames(int $ticket_id): string
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_users.name',
                'glpi_users.firstname',
                'glpi_users.realname',
                'glpi_tickets_users.alternative_email',
            ],
            'FROM'      => 'glpi_tickets_users',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'FKEY' => [
                        'glpi_tickets_users' => 'users_id',
                        'glpi_users'         => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_tickets_users.tickets_id' => $ticket_id,
                'glpi_tickets_users.type'       => CommonITILActor::REQUESTER,
            ],
            'ORDER'     => ['glpi_tickets_users.id ASC'],
        ]);

        $names = [];
        foreach ($iterator as $row) {
            $name = self::formatUserRow($row);
            if ($name === '') {
                $name = trim((string) ($row['alternative_email'] ?? ''));
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(' | ', array_unique($names));
    }

    private static function getUserName(int $user_id): string
    {
        global $DB;

        if ($user_id <= 0) {
            return '';
        }

        $iterator = $DB->request([
            'SELECT' => ['name', 'firstname', 'realname'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['id' => $user_id],
            'LIMIT'  => 1,
        ]);

        return count($iterator) > 0 ? self::formatUserRow($iterator->current()) : '';
    }

    private static function getSupplierName(int $supplier_id): string
    {
        global $DB;

        if ($supplier_id <= 0) {
            return '';
        }

        $iterator = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => Supplier::getTable(),
            'WHERE'  => ['id' => $supplier_id],
            'LIMIT'  => 1,
        ]);

        return count($iterator) > 0 ? trim((string) ($iterator->current()['name'] ?? '')) : '';
    }

    private static function getCategoryName(int $category_id): string
    {
        if ($category_id <= 0) {
            return '';
        }

        $category = new ITILCategory();
        if (!$category->getFromDB($category_id)) {
            return '';
        }

        return trim((string) ($category->fields['completename'] ?? $category->fields['name'] ?? ''));
    }

    private static function formatGroupName(array $row): string
    {
        $name = trim((string) ($row['completename'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['name'] ?? ''));
        }

        return $name !== '' ? $name : 'Grupo #' . (int) ($row['id'] ?? 0);
    }

    private static function formatUserRow(array $row): string
    {
        $name = trim(trim((string) ($row['firstname'] ?? '')) . ' ' . trim((string) ($row['realname'] ?? '')));
        return $name !== '' ? $name : trim((string) ($row['name'] ?? ''));
    }

    private static function formatTicketStatus(int $status): string
    {
        return match ($status) {
            Ticket::INCOMING => 'Novo',
            Ticket::ASSIGNED => 'Em atendimento (atribuído)',
            Ticket::PLANNED => 'Em atendimento (planejado)',
            Ticket::WAITING => 'Pendente',
            Ticket::SOLVED => 'Solucionado',
            Ticket::CLOSED => 'Fechado',
            default => $status > 0 ? 'Status #' . $status : '',
        };
    }

    private static function currentDatetime(): string
    {
        return PluginTregopluginsOlaBusinessTimeService::normalizeDatetime(
            (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))
        );
    }
}
