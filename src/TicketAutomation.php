<?php

/**
 * Handles automatic KB linking and solution template injection.
 */
class PluginTregopluginsTicketAutomation
{
    public static function handleTicketCreated(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket) {
            return;
        }

        if (!PluginTregopluginsCategoryConfig::shouldAutoLinkKnowbase($item)) {
            return;
        }

        $knowbase_item_id = self::resolveDefaultKnowbaseItemId($item);
        if ($knowbase_item_id <= 0) {
            return;
        }

        if (self::ticketAlreadyLinkedToKnowbase($item->getID(), $knowbase_item_id)) {
            return;
        }

        $link = new KnowbaseItem_Item();
        $link->add([
            'knowbaseitems_id' => $knowbase_item_id,
            'itemtype'         => Ticket::class,
            'items_id'         => $item->getID(),
        ]);
    }

    public static function prepareTicketClosure(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket) {
            return;
        }

        if (!self::isTransitionToSolvedOrClosed($item)) {
            return;
        }

        if (self::hasExistingSolution($item->getID())) {
            return;
        }

        if (!empty($item->input['_solutiontemplates_id'])) {
            return;
        }

        $solution_template_id = PluginTregopluginsCategoryConfig::getSolutionTemplateIdForTicket($item);
        if ($solution_template_id <= 0) {
            return;
        }

        $item->input['_solutiontemplates_id'] = $solution_template_id;
    }

    public static function restartOlaTtoForGroupAssignment(CommonDBTM $item): void
    {
        if (!$item instanceof Group_Ticket) {
            return;
        }

        if ((int) ($item->fields['type'] ?? $item->input['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticket = self::getTicketFromActorLink($item);
        if ($ticket === null) {
            return;
        }

        self::restartOlaTtoCycle($ticket, (int) ($item->fields['groups_id'] ?? $item->input['groups_id'] ?? 0));
    }

    public static function restartOlaTtoForGroupChange(CommonDBTM $item): void
    {
        if (!$item instanceof Group_Ticket) {
            return;
        }

        $updated_fields = (array) ($item->updates ?? []);
        if (!in_array('groups_id', $updated_fields, true) && !in_array('type', $updated_fields, true)) {
            return;
        }

        self::restartOlaTtoForGroupAssignment($item);
    }

    public static function markOlaTtoAssigned(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket_User && !$item instanceof Supplier_Ticket) {
            return;
        }

        if ((int) ($item->fields['type'] ?? $item->input['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }

        $ticket = self::getTicketFromActorLink($item);
        if ($ticket === null || (int) ($ticket->fields['olas_id_tto'] ?? 0) <= 0) {
            return;
        }

        $takeintoaccount_date = trim((string) ($ticket->fields['takeintoaccountdate'] ?? ''));
        if ($takeintoaccount_date !== '') {
            return;
        }

        $assigned_at = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $cycle_start = trim((string) ($ticket->fields['ola_tto_begin_date'] ?? ''));
        if ($cycle_start === '') {
            $cycle_start = trim((string) ($ticket->fields['date'] ?? $assigned_at));
        }

        self::updateTicketOlaFields(
            $ticket->getID(),
            [
                'takeintoaccountdate'        => $assigned_at,
                'takeintoaccount_delay_stat' => PluginTregopluginsOlaBusinessTimeService::getActiveTimeBetween(
                    $ticket,
                    $cycle_start,
                    $assigned_at,
                    PluginTregopluginsOlaBusinessTimeService::getCurrentAssignedGroupId($ticket->getID())
                ),
            ]
        );
    }

    public static function prepareSolutionCreation(CommonDBTM $item): void
    {
        if (!$item instanceof ITILSolution) {
            return;
        }

        if (!is_array($item->input)) {
            return;
        }

        $itemtype = $item->input['itemtype'] ?? '';
        if ($itemtype !== Ticket::class && $itemtype !== 'Ticket') {
            return;
        }

        if (!empty($item->input['_solutiontemplates_id'])) {
            return;
        }

        if (self::hasMeaningfulContent($item->input['content'] ?? null)) {
            return;
        }

        $ticket_id = (int) ($item->input['items_id'] ?? 0);
        if ($ticket_id <= 0 || self::hasExistingSolution($ticket_id)) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id)) {
            return;
        }

        $solution_template_id = PluginTregopluginsCategoryConfig::getSolutionTemplateIdForTicket($ticket);
        if ($solution_template_id <= 0) {
            return;
        }

        $item->input['_solutiontemplates_id'] = $solution_template_id;
    }

    public static function prefillSolutionDraft(ITILSolution $solution, Ticket $ticket): void
    {
        if ($solution->getID() > 0) {
            return;
        }

        if (self::hasExistingSolution($ticket->getID())) {
            return;
        }

        if (self::hasMeaningfulContent($solution->fields['content'] ?? null)) {
            return;
        }

        $solution_template_id = PluginTregopluginsCategoryConfig::getSolutionTemplateIdForTicket($ticket);
        if ($solution_template_id <= 0) {
            return;
        }

        $template = new SolutionTemplate();
        if (!$template->getFromDB($solution_template_id)) {
            return;
        }

        $rendered_content = $template->getRenderedContent($ticket);
        if ($rendered_content === null) {
            return;
        }

        $solution->fields['content'] = \Glpi\Toolbox\Sanitizer::sanitize($rendered_content);
        $solution->fields['solutiontypes_id'] = (int) ($template->fields['solutiontypes_id'] ?? 0);
    }

    private static function resolveDefaultKnowbaseItemId(Ticket $ticket): int
    {
        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($category_id <= 0) {
            return 0;
        }

        $itil_category = new ITILCategory();
        if (!$itil_category->getFromDB($category_id)) {
            return 0;
        }

        $knowbase_category_id = (int) ($itil_category->fields['knowbaseitemcategories_id'] ?? 0);
        if ($knowbase_category_id <= 0) {
            return 0;
        }

        $visible_ids = array_values(array_filter(
            array_map('intval', KnowbaseItem::getForCategory($knowbase_category_id)),
            static fn(int $id): bool => $id > 0
        ));

        if (count($visible_ids) > 0) {
            return (int) reset($visible_ids);
        }

        return self::findFirstKnowbaseItemIdForCategory($knowbase_category_id);
    }

    private static function isTransitionToSolvedOrClosed(Ticket $ticket): bool
    {
        if (!array_key_exists('status', $ticket->input)) {
            return false;
        }

        $new_status = (int) $ticket->input['status'];
        $closing_statuses = array_merge(
            $ticket->getSolvedStatusArray(),
            $ticket->getClosedStatusArray()
        );

        if (!in_array($new_status, $closing_statuses, true)) {
            return false;
        }

        return !in_array((int) $ticket->fields['status'], $closing_statuses, true);
    }

    private static function hasExistingSolution(int $ticket_id): bool
    {
        if ($ticket_id <= 0) {
            return false;
        }

        return ITILSolution::countFor(Ticket::class, $ticket_id) > 0;
    }

    private static function hasMeaningfulContent(?string $content): bool
    {
        if ($content === null) {
            return false;
        }

        $plain_text = html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain_text = preg_replace('/\x{00a0}/u', ' ', $plain_text);

        return trim((string) $plain_text) !== '';
    }

    private static function getTicketFromActorLink(CommonDBTM $item): ?Ticket
    {
        $ticket_id = (int) ($item->fields['tickets_id'] ?? $item->input['tickets_id'] ?? 0);
        if ($ticket_id <= 0) {
            return null;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id)) {
            return null;
        }

        return $ticket;
    }

    private static function restartOlaTtoCycle(Ticket $ticket, int $group_id): void
    {
        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id <= 0) {
            return;
        }

        $started_at = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $due_date = PluginTregopluginsOlaBusinessTimeService::computeDueDate($ticket, $started_at, $group_id);
        if ($due_date === null) {
            return;
        }

        self::updateTicketOlaFields(
            $ticket->getID(),
            [
                'ola_tto_begin_date'         => $started_at,
                'internal_time_to_own'       => $due_date,
                'ola_waiting_duration'       => 0,
                'takeintoaccountdate'        => null,
                'takeintoaccount_delay_stat' => 0,
            ]
        );
    }

    /**
     * Update the OLA timing fields without going through Ticket::update(), which
     * would trigger another actor/status update pass while GLPI is still adding
     * the assignment relation.
     *
     * @param array<string, mixed> $fields
     */
    private static function updateTicketOlaFields(int $ticket_id, array $fields): void
    {
        global $DB;

        if ($ticket_id <= 0 || count($fields) === 0) {
            return;
        }

        $DB->update(
            Ticket::getTable(),
            $fields,
            ['id' => $ticket_id]
        );
    }

    private static function ticketAlreadyLinkedToKnowbase(int $ticket_id, int $knowbase_item_id): bool
    {
        if ($ticket_id <= 0 || $knowbase_item_id <= 0) {
            return false;
        }

        return countElementsInTable(
            KnowbaseItem_Item::getTable(),
            [
                'knowbaseitems_id' => $knowbase_item_id,
                'itemtype'         => Ticket::class,
                'items_id'         => $ticket_id,
            ]
        ) > 0;
    }

    private static function findFirstKnowbaseItemIdForCategory(int $knowbase_category_id): int
    {
        global $DB;

        if ($knowbase_category_id <= 0) {
            return 0;
        }

        $where = [
            'glpi_knowbaseitems_knowbaseitemcategories.knowbaseitemcategories_id' => $knowbase_category_id,
        ];

        if ($DB->fieldExists('glpi_knowbaseitems', 'begin_date')) {
            $where[] = [
                'OR' => [
                    ['glpi_knowbaseitems.begin_date' => null],
                    ['glpi_knowbaseitems.begin_date' => ['<=', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')]],
                ],
            ];
        }

        if ($DB->fieldExists('glpi_knowbaseitems', 'end_date')) {
            $where[] = [
                'OR' => [
                    ['glpi_knowbaseitems.end_date' => null],
                    ['glpi_knowbaseitems.end_date' => ['>=', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')]],
                ],
            ];
        }

        $iterator = $DB->request([
            'SELECT'     => ['glpi_knowbaseitems.id'],
            'FROM'       => 'glpi_knowbaseitems',
            'INNER JOIN' => [
                'glpi_knowbaseitems_knowbaseitemcategories' => [
                    'ON' => [
                        'glpi_knowbaseitems_knowbaseitemcategories' => 'knowbaseitems_id',
                        'glpi_knowbaseitems'                        => 'id',
                    ],
                ],
            ],
            'WHERE'      => $where,
            'ORDER'      => ['glpi_knowbaseitems.id ASC'],
            'LIMIT'      => 1,
        ]);

        if (count($iterator) === 0) {
            return 0;
        }

        $row = $iterator->current();

        return (int) ($row['id'] ?? 0);
    }
}
