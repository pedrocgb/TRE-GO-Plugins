<?php

/**
 * Side-effect-free replay of RuleTicket ONADD business rules against an
 * existing ticket's current data. Powers both button eligibility (dry run)
 * and the actual manual dispatch (result is mapped and applied by
 * PluginTregopluginsTicketDispatchService).
 *
 * Uses the same core rule engine GLPI itself calls from
 * Ticket::prepareInputForAdd() (RuleTicketCollection::processAllRules with
 * RuleTicket::ONADD). That engine class is part of GLPI's stable rules
 * subsystem and is used unchanged here for both GLPI 10.x and 11.x; no
 * private/reflection access is used.
 */
class PluginTregopluginsTicketDispatchRuleReplay
{
    /**
     * Direct ticket fields a RuleTicket ONADD action is allowed to change
     * and that this module is prepared to copy back into a real update.
     * Deliberately excludes appliance/project/contract/task/followup
     * template actions: those are not part of the technician-group routing
     * concern this module owns, and copying them back without dedicated
     * fixtures would risk silently misapplying a partial action.
     *
     * @var string[]
     */
    private const MAPPABLE_DIRECT_FIELDS = [
        'itilcategories_id',
        'type',
        'urgency',
        'impact',
        'priority',
        'status',
        'locations_id',
        'requesttypes_id',
        'slas_id_ttr',
        'slas_id_tto',
        'olas_id_ttr',
        'olas_id_tto',
        'global_validation',
    ];

    /**
     * Actor/helper keys a RuleTicket ONADD action is allowed to produce.
     *
     * @var string[]
     */
    private const MAPPABLE_ACTOR_FIELDS = [
        '_users_id_requester',
        '_additional_requesters',
        '_groups_id_requester',
        '_additional_groups_requesters',
        '_users_id_assign',
        '_additional_assigns',
        '_groups_id_assign',
        '_additional_groups_assigns',
        '_suppliers_id_assign',
        '_additional_suppliers_assigns',
        '_users_id_observer',
        '_additional_observers',
        '_groups_id_observer',
        '_additional_groups_observers',
        '_add_validation',
        'users_id_validate',
        'groups_id_validate',
        'validation_percent',
    ];

    /**
     * Run RuleTicket ONADD rules against the ticket's current data and
     * return the raw rule-engine output. Never persists anything, sends no
     * notification, creates no validation: rule input `id` is forced to 0
     * so notification-type actions cannot resolve a real ticket to notify.
     *
     * @return array<string, mixed>
     */
    public function evaluate(Ticket $ticket): array
    {
        return $this->evaluateFull($ticket)['output'];
    }

    /**
     * Same as evaluate(), but also returns the input snapshot that was fed
     * to the rule engine, so a caller (the dispatch service) can diff
     * input vs output itself via mapResult().
     *
     * @return array{input: array<string, mixed>, output: array<string, mixed>}
     */
    public function evaluateFull(Ticket $ticket): array
    {
        $input = $this->buildInput($ticket);

        $rules = new RuleTicketCollection((int) $input['entities_id']);

        $output = $rules->processAllRules(
            $input,
            $input,
            ['recursive' => true],
            ['condition' => RuleTicket::ONADD]
        );

        return [
            'input'  => $input,
            'output' => Toolbox::stripslashes_deep($output),
        ];
    }

    /**
     * Extract only the rule-produced, supported mutations from an evaluate()
     * result, by diffing it against the input snapshot that was fed in.
     * Excludes anything not in the mappable whitelist (ids, dates,
     * counters, display-only data, unsupported actions).
     *
     * @param array<string, mixed> $input  the snapshot returned by buildInput()
     * @param array<string, mixed> $output the array returned by evaluate()
     *
     * @return array<string, mixed>
     */
    public function mapResult(array $input, array $output): array
    {
        $mapped = [];

        foreach (self::MAPPABLE_DIRECT_FIELDS as $field) {
            if (!array_key_exists($field, $output)) {
                continue;
            }
            if (array_key_exists($field, $input) && $input[$field] === $output[$field]) {
                continue;
            }
            $mapped[$field] = $output[$field];
        }

        foreach (self::MAPPABLE_ACTOR_FIELDS as $field) {
            if (!array_key_exists($field, $output)) {
                continue;
            }
            if (array_key_exists($field, $input) && $input[$field] === $output[$field]) {
                continue;
            }
            $mapped[$field] = $output[$field];
        }

        return $mapped;
    }

    /**
     * Build a rule-input snapshot from the ticket's current DB state,
     * mirroring the fields Ticket::prepareInputForAdd()/
     * fillInputForBusinessRules() feed into the same rule engine at real
     * creation time. `id` is forced to 0 (synthetic/new-item semantics) so
     * dry-run evaluation cannot be mistaken by a rule action for an
     * existing, notifiable ticket.
     *
     * @return array<string, mixed>
     */
    private function buildInput(Ticket $ticket): array
    {
        $fields = $ticket->fields;

        $input = $fields;
        $input['id'] = 0;
        $input['entities_id'] = (int) $fields['entities_id'];

        if (!empty($fields['itilcategories_id'])) {
            $category = ITILCategory::getById((int) $fields['itilcategories_id']);
            if ($category !== false) {
                $input['itilcategories_id_code'] = $category->fields['code'] ?? '';
            }
        }

        $this->fillRequesterContext($ticket, $input);
        $this->fillActors($ticket, $input);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fillRequesterContext(Ticket $ticket, array &$input): void
    {
        $requesters = $ticket->getUsers(CommonITILActor::REQUESTER);
        $first_requester = reset($requesters);

        if ($first_requester === false) {
            return;
        }

        $user = new User();
        if (!$user->getFromDB((int) $first_requester['users_id'])) {
            return;
        }

        $input['_locations_id_of_requester'] = $user->fields['locations_id'] ?? 0;
        $input['users_default_groups'] = $user->fields['groups_id'] ?? 0;
        $input['profiles_id'] = $user->fields['profiles_id'] ?? 0;
    }

    /**
     * Translate the ticket's current actor relations back into the same
     * `_..._id_<type>` / `_additional_..._<type>` input keys RuleTicket
     * criteria and actions read and write.
     *
     * @param array<string, mixed> $input
     */
    private function fillActors(Ticket $ticket, array &$input): void
    {
        $map = [
            CommonITILActor::REQUESTER => ['users_id' => '_users_id_requester', 'groups_id' => '_groups_id_requester'],
            CommonITILActor::OBSERVER  => ['users_id' => '_users_id_observer', 'groups_id' => '_groups_id_observer'],
            CommonITILActor::ASSIGN    => ['users_id' => '_users_id_assign', 'groups_id' => '_groups_id_assign'],
        ];

        foreach ($map as $type => $keys) {
            $user_ids = array_values(array_map(
                static fn(array $row): int => (int) $row['users_id'],
                $ticket->getUsers($type)
            ));
            if (count($user_ids) > 0) {
                $input[$keys['users_id']] = count($user_ids) === 1 ? $user_ids[0] : $user_ids;
            }

            $group_ids = array_values(array_map(
                static fn(array $row): int => (int) $row['groups_id'],
                $ticket->getGroups($type)
            ));
            if (count($group_ids) > 0) {
                $input[$keys['groups_id']] = count($group_ids) === 1 ? $group_ids[0] : $group_ids;
            }
        }

        $supplier_ids = array_values(array_map(
            static fn(array $row): int => (int) $row['suppliers_id'],
            $ticket->getSuppliers(CommonITILActor::ASSIGN)
        ));
        if (count($supplier_ids) > 0) {
            $input['_suppliers_id_assign'] = count($supplier_ids) === 1 ? $supplier_ids[0] : $supplier_ids;
        }
    }
}
