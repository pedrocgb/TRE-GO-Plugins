<?php

/**
 * Computes the two indicators the spec asks for, kept distinct on purpose:
 * visual progress (all active items) and completion eligibility (only
 * active + required items). No persisted counter cache: aggregated on read
 * to avoid the drift risk a cache would introduce for a low-volume table.
 */
class PluginTregopluginsChecklistProgress
{
    /**
     * @return array{done: int, total: int, pending_required: string[]}
     */
    public static function compute(int $taskchecklists_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'COUNT'  => 'id AS total',
                new QueryExpression('SUM(`is_checked`) AS done'),
            ],
            'FROM'   => PluginTregopluginsTaskChecklistItem::getTable(),
            'WHERE'  => ['taskchecklists_id' => $taskchecklists_id, 'is_active' => 1],
        ]);
        $row = $iterator->current();

        return [
            'done'             => (int) ($row['done'] ?? 0),
            'total'            => (int) ($row['total'] ?? 0),
            'pending_required' => self::getPendingRequiredNames($taskchecklists_id),
        ];
    }

    /**
     * @return string[] snapshot names of active, required, unchecked items
     */
    public static function getPendingRequiredNames(int $taskchecklists_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['name_snapshot'],
            'FROM'   => PluginTregopluginsTaskChecklistItem::getTable(),
            'WHERE'  => [
                'taskchecklists_id'    => $taskchecklists_id,
                'is_active'            => 1,
                'is_required_snapshot' => 1,
                'is_checked'           => 0,
            ],
            'ORDER'  => 'position_snapshot ASC',
        ]);

        $names = [];
        foreach ($iterator as $row) {
            $names[] = $row['name_snapshot'];
        }

        return $names;
    }

    public static function isEligibleForCompletion(int $taskchecklists_id): bool
    {
        return count(self::getPendingRequiredNames($taskchecklists_id)) === 0;
    }
}
