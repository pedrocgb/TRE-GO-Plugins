<?php

/**
 * Server-side eligibility calculation for the "Despachar Chamado" timeline
 * action. The exact same result powers both the button's visible/disabled
 * state and the dispatch endpoint's own authorization check, so a button can
 * never be more permissive than the server (and the server never trusts what
 * the button showed).
 */
class PluginTregopluginsTicketDispatchEligibility
{
    public const OK = 'ok';
    public const MODULE_DISABLED = 'module_disabled';
    public const NO_PERMISSION = 'no_permission';
    public const TICKET_NOT_ACCESSIBLE = 'ticket_not_accessible';
    public const TICKET_CLOSED_OR_DELETED = 'ticket_closed_or_deleted';
    public const ALREADY_DISPATCHED = 'already_dispatched';
    public const NO_CATEGORY = 'no_category';
    public const NO_APPLICABLE_RULE = 'no_applicable_rule';
    public const INVALID_CALCULATED_GROUP = 'invalid_calculated_group';

    /**
     * @return array{
     *   allowed: bool,
     *   reason_code: string,
     *   reason_label: string,
     *   current_group_ids: int[],
     *   calculated_group_ids: int[],
     *   matched_rule_ids: int[],
     * }
     */
    public static function evaluate(Ticket $ticket): array
    {
        if (!PluginTregopluginsTicketDispatchConfig::isRunnable()) {
            return self::result(false, self::MODULE_DISABLED, [], [], []);
        }

        if (!PluginTregopluginsTicketDispatchProfile::canUseDispatchAction()) {
            return self::result(false, self::NO_PERMISSION, [], [], []);
        }

        $ticket_id = (int) $ticket->getID();
        if (
            $ticket_id <= 0
            || !$ticket->can($ticket_id, READ)
            || !$ticket->canUpdateItem()
            || !$ticket->canAssign()
        ) {
            return self::result(false, self::TICKET_NOT_ACCESSIBLE, [], [], []);
        }

        if (
            (int) ($ticket->fields['is_deleted'] ?? 0) === 1
            || in_array((int) ($ticket->fields['status'] ?? 0), $ticket->getClosedStatusArray(), true)
        ) {
            return self::result(false, self::TICKET_CLOSED_OR_DELETED, [], [], []);
        }

        $current_group_ids = array_values(array_map(
            static fn(array $row): int => (int) $row['groups_id'],
            $ticket->getGroups(CommonITILActor::ASSIGN)
        ));
        sort($current_group_ids);

        if (PluginTregopluginsTicketDispatchService::hasBeenDispatched($ticket_id)) {
            return self::result(false, self::ALREADY_DISPATCHED, $current_group_ids, [], []);
        }

        if ((int) ($ticket->fields['itilcategories_id'] ?? 0) <= 0) {
            return self::result(false, self::NO_CATEGORY, $current_group_ids, [], []);
        }

        $replay = new PluginTregopluginsTicketDispatchRuleReplay();
        $output = $replay->evaluate($ticket);

        $calculated_group_ids = self::extractGroupIds($output);
        sort($calculated_group_ids);

        $matched_rule_ids = isset($output['_ruleid']) ? [(int) $output['_ruleid']] : [];

        if (count($calculated_group_ids) === 0) {
            return self::result(false, self::NO_APPLICABLE_RULE, $current_group_ids, $calculated_group_ids, $matched_rule_ids);
        }

        foreach ($calculated_group_ids as $group_id) {
            if (!self::isGroupAssignable($group_id)) {
                return self::result(false, self::INVALID_CALCULATED_GROUP, $current_group_ids, $calculated_group_ids, $matched_rule_ids);
            }
        }

        return self::result(true, self::OK, $current_group_ids, $calculated_group_ids, $matched_rule_ids);
    }

    public static function reasonLabel(string $reason_code): string
    {
        $labels = [
            self::OK                          => __('Elegível para despacho.', 'tregoplugins'),
            self::MODULE_DISABLED             => __('Módulo de despacho desativado.', 'tregoplugins'),
            self::NO_PERMISSION                => __('Sem permissão para despachar chamados.', 'tregoplugins'),
            self::TICKET_NOT_ACCESSIBLE        => __('Sem permissão para visualizar/atribuir este chamado.', 'tregoplugins'),
            self::TICKET_CLOSED_OR_DELETED     => __('Chamado encerrado ou excluído.', 'tregoplugins'),
            self::ALREADY_DISPATCHED           => __('Chamado já foi despachado.', 'tregoplugins'),
            self::NO_CATEGORY                  => __('Chamado sem categoria.', 'tregoplugins'),
            self::NO_APPLICABLE_RULE           => __('Nenhuma regra de unidade responsável aplicável.', 'tregoplugins'),
            self::INVALID_CALCULATED_GROUP     => __('A unidade calculada não é válida para este chamado.', 'tregoplugins'),
        ];

        return $labels[$reason_code] ?? $reason_code;
    }

    private static function isGroupAssignable(int $groups_id): bool
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
     * @param int[] $current_group_ids
     * @param int[] $calculated_group_ids
     * @param int[] $matched_rule_ids
     *
     * @return array{allowed: bool, reason_code: string, reason_label: string, current_group_ids: int[], calculated_group_ids: int[], matched_rule_ids: int[]}
     */
    private static function result(
        bool $allowed,
        string $reason_code,
        array $current_group_ids,
        array $calculated_group_ids,
        array $matched_rule_ids
    ): array {
        return [
            'allowed'               => $allowed,
            'reason_code'           => $reason_code,
            'reason_label'          => self::reasonLabel($reason_code),
            'current_group_ids'     => $current_group_ids,
            'calculated_group_ids'  => $calculated_group_ids,
            'matched_rule_ids'      => $matched_rule_ids,
        ];
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return int[]
     */
    private static function extractGroupIds(array $output): array
    {
        $ids = [];

        if (!empty($output['_groups_id_assign'])) {
            $ids = array_merge($ids, (array) $output['_groups_id_assign']);
        }
        if (!empty($output['_additional_groups_assigns'])) {
            $ids = array_merge($ids, (array) $output['_additional_groups_assigns']);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }
}
