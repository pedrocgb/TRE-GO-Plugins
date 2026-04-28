<?php

/**
 * Prefills solution drafts shown in the ticket timeline.
 */
class PluginTregopluginsSolutionForm
{
    public static function preItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        $parent = $params['options']['item'] ?? null;

        if (!$item instanceof ITILSolution || !$parent instanceof Ticket) {
            return;
        }

        PluginTregopluginsTicketAutomation::prefillSolutionDraft($item, $parent);
    }
}
