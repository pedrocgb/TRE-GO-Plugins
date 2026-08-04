<?php

/**
 * Picks exactly one PluginTregopluginsChecklistBinding for a given
 * TaskTemplate + Ticket context, per the deterministic order documented in
 * the module spec:
 *  1. highest explicit priority;
 *  2. highest specificity (category+type > category > type > generic);
 *  3. exact entity match before a recursive ancestor match;
 *  4. lowest id as the final tie-break.
 */
class PluginTregopluginsChecklistBindingResolver
{
    /**
     * @return array<string, mixed>|null the winning binding row, or null if
     *         none applies (or its checklist template is inactive/empty)
     */
    public static function resolve(int $tasktemplates_id, Ticket $ticket): ?array
    {
        global $DB;

        if ($tasktemplates_id <= 0) {
            return null;
        }

        $ticket_type = (int) ($ticket->fields['type'] ?? 0);
        $itilcategories_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        $ticket_entities_id = (int) ($ticket->fields['entities_id'] ?? 0);
        $ancestor_ids = getAncestorsOf(Entity::getTable(), $ticket_entities_id);

        $iterator = $DB->request([
            'FROM'  => PluginTregopluginsChecklistBinding::getTable(),
            'WHERE' => [
                'tasktemplates_id' => $tasktemplates_id,
                'is_active'        => 1,
                'ticket_type'      => [PluginTregopluginsChecklistBinding::ANY_TICKET_TYPE, $ticket_type],
                'itilcategories_id' => [PluginTregopluginsChecklistBinding::ANY_ITILCATEGORY, $itilcategories_id],
            ],
        ]);

        $candidates = [];
        foreach ($iterator as $row) {
            $binding_entity_id = (int) $row['entities_id'];
            $exact_entity = $binding_entity_id === $ticket_entities_id;
            $recursive_match = (int) $row['is_recursive'] === 1 && in_array($binding_entity_id, $ancestor_ids, true);

            if (!$exact_entity && !$recursive_match) {
                continue;
            }

            $specificity = 0;
            if ((int) $row['itilcategories_id'] !== PluginTregopluginsChecklistBinding::ANY_ITILCATEGORY) {
                $specificity += 2;
            }
            if ((int) $row['ticket_type'] !== PluginTregopluginsChecklistBinding::ANY_TICKET_TYPE) {
                $specificity += 1;
            }

            $candidates[] = [
                'row'          => $row,
                'priority'     => (int) $row['priority'],
                'specificity'  => $specificity,
                'exact_entity' => $exact_entity ? 1 : 0,
                'id'           => (int) $row['id'],
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            return [$b['priority'], $b['specificity'], $b['exact_entity'], -$a['id']]
                <=> [$a['priority'], $a['specificity'], $a['exact_entity'], -$b['id']];
        });

        foreach ($candidates as $candidate) {
            $checklist_template = new PluginTregopluginsChecklistTemplate();
            if (
                $checklist_template->getFromDB($candidate['row']['checklisttemplates_id'])
                && (int) $checklist_template->fields['is_active'] === 1
                && $checklist_template->hasAtLeastOneActiveItem()
            ) {
                return $candidate['row'];
            }
        }

        return null;
    }
}
