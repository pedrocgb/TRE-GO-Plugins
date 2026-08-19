<?php

/**
 * Builds the OLA TTO progress bar shown in the ticket list.
 */
class PluginTregopluginsOlaProgressService
{
    public static function renderProgressCell(Ticket $ticket): string
    {
        if ((int) ($ticket->fields['olas_id_tto'] ?? 0) <= 0) {
            return self::renderInfoCell('OLA Não configurado');
        }

        $due_date = self::resolveDueDate($ticket);
        if ($due_date === null) {
            return self::renderInfoCell('OLA TTO sem prazo');
        }

        $due_date_label = Html::convDateTime($due_date);

        $timing = self::computeActiveTimes($ticket, $due_date);
        if ($timing === null) {
            return self::renderInfoCell('OLA TTO sem inicio');
        }

        $percentage = self::computePercent(
            $timing['currenttime'],
            $timing['totaltime'],
            $timing['waitingtime']
        );

        $color = self::resolveProgressColor($percentage);
        $status_label = self::resolveStatusLabel($ticket, $percentage);

        return self::renderCellMarkup($due_date_label, $percentage, $color, $status_label);
    }

    /**
     * Mirrors GLPI core Search::giveItem() logic for search option 186 so the
     * plugin column follows the same OLA/calendar semantics as the native one.
     */
    private static function computeActiveTimes(Ticket $ticket, string $due_date): ?array
    {
        $start_date = self::resolveStartDate($ticket);
        if ($start_date === null) {
            return null;
        }

        $group_id = PluginTregopluginsOlaBusinessTimeService::getCurrentAssignedGroupId((int) $ticket->getID());

        // Prefer the plugin's own OLA Report pass-history timer once a real
        // technician is assigned, instead of recomputing "elapsed time"
        // from native ticket fields (takeintoaccountdate/ola_tto_begin_date).
        // working_seconds_to_assignment is written exactly once, at the
        // real assignment moment, by OlaReportRepository::assignOpenPass()
        // using this same group's pass_started_at -- it does not depend on
        // GLPI core's takeintoaccountdate stamping at all, so it stays
        // correct even in cases not covered by
        // TicketAutomation::preventPrematureTakeIntoAccount().
        $tracked_elapsed = self::resolvePassTrackedElapsedSeconds((int) $ticket->getID(), $group_id);

        $current_date = self::resolveProgressEndDate($ticket);
        $currenttime = 0;
        $totaltime = 0;
        $waitingtime = 0;

        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id > 0) {
            $currenttime = $tracked_elapsed ?? PluginTregopluginsOlaBusinessTimeService::getActiveTimeBetween(
                $ticket,
                $start_date,
                $current_date,
                $group_id
            );
            $totaltime = PluginTregopluginsOlaBusinessTimeService::getActiveTimeBetween(
                $ticket,
                $start_date,
                $due_date,
                $group_id
            );
        }

        if ($totaltime <= 0) {
            $calendar_id = Entity::getUsedConfig(
                'calendars_strategy',
                (int) ($ticket->fields['entities_id'] ?? 0),
                'calendars_id',
                0
            );

            $calendar = new Calendar();
            if ($calendar_id > 0 && $calendar->getFromDB($calendar_id)) {
                $currenttime = $tracked_elapsed ?? $calendar->getActiveTimeBetween($start_date, $current_date);
                $totaltime = $calendar->getActiveTimeBetween($start_date, $due_date);
            } else {
                $currenttime = $tracked_elapsed ?? (strtotime($current_date) - strtotime($start_date));
                $totaltime = strtotime($due_date) - strtotime($start_date);
            }
        }

        return [
            'currenttime' => max(0, (int) $currenttime),
            'totaltime'   => max(0, (int) $totaltime),
            'waitingtime' => $waitingtime,
        ];
    }

    /**
     * @return int|null Elapsed active seconds tracked by our own OLA Report
     *                   pass history for the ticket's *current* group cycle,
     *                   or null if no reliable tracked value applies (not
     *                   assigned yet in this cycle, stale pass from a prior
     *                   group, or OLA Report tracking unavailable) -- the
     *                   caller then falls back to the native-field
     *                   calculation unchanged.
     */
    private static function resolvePassTrackedElapsedSeconds(int $ticket_id, int $group_id): ?int
    {
        if ($ticket_id <= 0 || $group_id <= 0) {
            return null;
        }

        $pass = PluginTregopluginsOlaReportRepository::getLatestPassSnapshot($ticket_id);
        if ($pass === null || (int) ($pass['groups_id'] ?? 0) !== $group_id) {
            return null;
        }

        $assigned_at = trim((string) ($pass['assigned_at'] ?? ''));
        if ($assigned_at === '') {
            return null;
        }

        return max(0, (int) ($pass['working_seconds_to_assignment'] ?? 0));
    }

    private static function computePercent(
        int $currenttime,
        int $totaltime,
        int $waitingtime
    ): int {
        if (($totaltime - $waitingtime) === 0) {
            return 100;
        }

        $percentage = (int) round(
            (100 * ($currenttime - $waitingtime)) / ($totaltime - $waitingtime)
        );

        return min(100, max(0, $percentage));
    }

    private static function resolveProgressColor(int $percentage): string
    {
        if ($percentage >= 100) {
            return '#d63939';
        }

        if ($percentage >= 75) {
            return '#fd7e14';
        }

        if ($percentage >= 50) {
            return '#f7c948';
        }

        return '#2fb344';
    }

    private static function renderCellMarkup(
        string $due_date_label,
        ?int $percentage = null,
        ?string $color = null,
        ?string $status_label = null
    ): string {
        if ($percentage === null || $color === null) {
            return "<div class='tregoplugins-ola-progress-cell'><span class='text-nowrap'>{$due_date_label}</span></div>";
        }

        $status_markup = $status_label !== null
            ? "<span class='tregoplugins-ola-progress-status'>{$status_label}</span>"
            : '';

        return <<<HTML
<div class="tregoplugins-ola-progress-cell">
   <span class="text-nowrap">{$due_date_label}</span>
   {$status_markup}
   <div class="progress tregoplugins-ola-progress-bar" style="height: 16px">
      <div class="progress-bar progress-bar-striped" role="progressbar"
           style="width: {$percentage}%; background-color: {$color};"
           aria-valuenow="{$percentage}" aria-valuemin="0" aria-valuemax="100">
      </div>
      <span class="tregoplugins-ola-progress-label">{$percentage}%</span>
   </div>
</div>
HTML;
    }

    private static function renderInfoCell(string $message): string
    {
        return "<div class='tregoplugins-ola-progress-cell'><span class='text-muted'>{$message}</span></div>";
    }

    private static function resolveStatusLabel(Ticket $ticket, int $percentage): ?string
    {
        // Solved/closed always wins: the OLA TTO clock is frozen (see
        // resolveProgressEndDate) and nothing else about "Atribuído"/"Em
        // Pausa" is relevant once the ticket is done. A reopen flips status
        // back below CLOSED and this override stops applying on its own.
        if (self::isClosedStatus((int) ($ticket->fields['status'] ?? 0))) {
            return 'Chamado finalizado';
        }

        // Already taken into account: that state always wins over the
        // calendar-pause label below, whether the clock is currently inside
        // or outside working hours.
        if (self::resolveAssignmentPauseDate($ticket) !== null) {
            return $percentage >= 100 ? 'Atrasado' : 'Atribuído';
        }

        if ($percentage >= 100) {
            return 'Tempo Excedido';
        }

        if (self::isOutsideWorkingHours($ticket)) {
            return 'Em Pausa: fora do horário de serviço';
        }

        return null;
    }

    /**
     * Whether the group currently assigned to the ticket is, right now,
     * outside its resolved calendar's working hours -- i.e. the OLA TTO
     * clock is effectively paused. Uses the same group/calendar resolution
     * as the due-date computation, so this always reflects whichever group
     * and calendar are currently in effect (including right after a
     * dispatch/escalation resets the OLA cycle).
     */
    private static function isOutsideWorkingHours(Ticket $ticket): bool
    {
        $group_id = PluginTregopluginsOlaBusinessTimeService::getCurrentAssignedGroupId((int) $ticket->getID());
        $calendar = PluginTregopluginsOlaBusinessTimeService::getCalendarForTicketGroup($ticket, $group_id);

        if (!$calendar instanceof Calendar) {
            return false;
        }

        return !$calendar->isAWorkingHour(time());
    }

    private static function isClosedStatus(int $status): bool
    {
        return in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true);
    }

    private static function resolveDueDate(Ticket $ticket): ?string
    {
        $group_id = PluginTregopluginsOlaBusinessTimeService::getCurrentAssignedGroupId((int) $ticket->getID());
        if ($group_id > 0 && PluginTregopluginsOlaBusinessTimeService::getGroupCalendarId($group_id) > 0) {
            $start_date = self::resolveStartDate($ticket);
            if ($start_date !== null) {
                $group_due_date = PluginTregopluginsOlaBusinessTimeService::computeDueDate(
                    $ticket,
                    $start_date,
                    $group_id
                );
                if ($group_due_date !== null) {
                    return $group_due_date;
                }
            }
        }

        $due_date = trim((string) ($ticket->fields['internal_time_to_own'] ?? ''));
        if ($due_date !== '') {
            return $due_date;
        }

        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id <= 0) {
            return null;
        }

        $start_date = self::resolveStartDate($ticket);
        if ($start_date === null) {
            return null;
        }

        $ola = new OLA();
        if (!$ola->getFromDB($ola_id)) {
            return null;
        }

        $ola->setTicketCalendar((int) ($ticket->getCalendar(SLM::TTO) ?? 0));

        return $ola->computeDate(
            $start_date,
            (int) ($ticket->fields['ola_waiting_duration'] ?? 0)
        );
    }

    private static function resolveStartDate(Ticket $ticket): ?string
    {
        $start_date = trim((string) ($ticket->fields['ola_tto_begin_date'] ?? ''));
        if ($start_date !== '') {
            return $start_date;
        }

        $opening_date = trim((string) ($ticket->fields['date'] ?? ''));

        return $opening_date !== '' ? $opening_date : null;
    }

    private static function resolveProgressEndDate(Ticket $ticket): string
    {
        // Solved/closed freezes the clock at the solve/close date instead of
        // "now", so the bar/percentage stop moving once the ticket is done.
        // A reopen (native GLPI rule) clears solvedate/closedate and flips
        // status back below CLOSED, so this branch stops applying on its own.
        if (self::isClosedStatus((int) ($ticket->fields['status'] ?? 0))) {
            $solve_date = trim((string) ($ticket->fields['solvedate'] ?? ''));
            if ($solve_date !== '') {
                return $solve_date;
            }

            $close_date = trim((string) ($ticket->fields['closedate'] ?? ''));
            if ($close_date !== '') {
                return $close_date;
            }
        }

        $pause_date = self::resolveAssignmentPauseDate($ticket);
        if ($pause_date !== null) {
            return $pause_date;
        }

        return date('Y-m-d H:i:s');
    }

    private static function resolveAssignmentPauseDate(Ticket $ticket): ?string
    {
        // takeintoaccountdate/takeintoaccount_delay_stat get stamped by GLPI
        // core as soon as ANY assign-type actor is attached, group included.
        // Only a real technician user/supplier means the ticket was actually
        // taken into account.
        if (!self::hasRealAssignedActor((int) $ticket->getID())) {
            return null;
        }

        $takeintoaccount_date = trim((string) ($ticket->fields['takeintoaccountdate'] ?? ''));
        if ($takeintoaccount_date !== '') {
            return $takeintoaccount_date;
        }

        $delay = (int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0);
        $start_date = self::resolveStartDate($ticket);
        if ($delay > 0 && $start_date !== null) {
            return date('Y-m-d H:i:s', strtotime($start_date) + $delay);
        }

        return null;
    }

    private static function hasRealAssignedActor(int $ticket_id): bool
    {
        global $DB;

        if ($ticket_id <= 0) {
            return false;
        }

        $user_iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN],
            'LIMIT'  => 1,
        ]);
        if (count($user_iterator) > 0) {
            return true;
        }

        $supplier_iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_suppliers_tickets',
            'WHERE'  => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN],
            'LIMIT'  => 1,
        ]);

        return count($supplier_iterator) > 0;
    }
}
