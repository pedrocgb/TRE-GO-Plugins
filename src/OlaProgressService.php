<?php

/**
 * Builds the OLA TTO progress bar shown in the ticket list.
 */
class PluginTregopluginsOlaProgressService
{
    public static function renderProgressCell(Ticket $ticket): string
    {
        $due_date = self::resolveDueDate($ticket);
        if ($due_date === null) {
            return '';
        }

        $due_date_label = Html::convDateTime($due_date);

        $timing = self::computeActiveTimes($ticket, $due_date);
        if ($timing === null) {
            return self::renderCellMarkup($due_date_label);
        }

        $percentage = self::computePercent(
            $timing['currenttime'],
            $timing['totaltime'],
            $timing['waitingtime']
        );

        $color = self::resolveProgressColor($percentage);

        return self::renderCellMarkup($due_date_label, $percentage, $color);
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

        $current_date = self::resolveProgressEndDate($ticket);
        $currenttime = 0;
        $totaltime = 0;
        $waitingtime = 0;

        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id > 0) {
            $ola = new OLA();
            if ($ola->getFromDB($ola_id)) {
                $ola->setTicketCalendar((int) ($ticket->getCalendar(SLM::TTO) ?? 0));
                $currenttime = $ola->getActiveTimeBetween($start_date, $current_date);
                $totaltime = $ola->getActiveTimeBetween($start_date, $due_date);
            }
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
                $currenttime = $calendar->getActiveTimeBetween($start_date, $current_date);
                $totaltime = $calendar->getActiveTimeBetween($start_date, $due_date);
            } else {
                $currenttime = strtotime($current_date) - strtotime($start_date);
                $totaltime = strtotime($due_date) - strtotime($start_date);
            }
        }

        return [
            'currenttime' => max(0, (int) $currenttime),
            'totaltime'   => max(0, (int) $totaltime),
            'waitingtime' => $waitingtime,
        ];
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
        ?string $color = null
    ): string {
        if ($percentage === null || $color === null) {
            return "<div class='tregoplugins-ola-progress-cell'><span class='text-nowrap'>{$due_date_label}</span></div>";
        }

        return <<<HTML
<div class="tregoplugins-ola-progress-cell">
   <span class="text-nowrap">{$due_date_label}</span>
   <div class="progress" style="height: 16px">
      <div class="progress-bar progress-bar-striped" role="progressbar"
           style="width: {$percentage}%; background-color: {$color};"
           aria-valuenow="{$percentage}" aria-valuemin="0" aria-valuemax="100">
         {$percentage}%
      </div>
   </div>
</div>
HTML;
    }

    private static function isClosedStatus(int $status): bool
    {
        return in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true);
    }

    private static function resolveDueDate(Ticket $ticket): ?string
    {
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
        $takeintoaccount_date = trim((string) ($ticket->fields['takeintoaccountdate'] ?? ''));
        if ($takeintoaccount_date !== '') {
            return $takeintoaccount_date;
        }

        $delay = (int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0);
        $opening_date = trim((string) ($ticket->fields['date'] ?? ''));
        if ($delay > 0 && $opening_date !== '') {
            return date('Y-m-d H:i:s', strtotime($opening_date) + $delay);
        }

        return date('Y-m-d H:i:s');
    }
}
