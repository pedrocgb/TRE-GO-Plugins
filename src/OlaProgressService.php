<?php

/**
 * Builds the OLA TTO progress bar shown in the ticket list.
 */
class PluginTregopluginsOlaProgressService
{
    public static function renderProgressCell(Ticket $ticket): string
    {
        $due_date = (string) ($ticket->fields['internal_time_to_own'] ?? '');
        if ($due_date === '') {
            return '';
        }

        $due_date_label = Html::convDateTime($due_date);
        if (
            self::isClosedStatus((int) ($ticket->fields['status'] ?? 0))
            || (int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0) > 0
        ) {
            return self::renderCellMarkup($due_date_label);
        }

        $color = null;
        if ((int) ($ticket->fields['status'] ?? 0) === CommonITILObject::WAITING) {
            $color = '#AAAAAA';
        }

        $timing = self::computeActiveTimes($ticket, $due_date);
        if ($timing === null) {
            return self::renderCellMarkup($due_date_label);
        }

        $percentage = self::computePercent(
            $timing['currenttime'],
            $timing['totaltime'],
            $timing['waitingtime']
        );

        if ($color === null) {
            $color = self::resolveProgressColor(
                $percentage,
                $timing['totaltime'],
                $timing['currenttime']
            );
        }

        return self::renderCellMarkup($due_date_label, $percentage, $color);
    }

    /**
     * Mirrors GLPI core Search::giveItem() logic for search option 186 so the
     * plugin column follows the same OLA/calendar semantics as the native one.
     */
    private static function computeActiveTimes(Ticket $ticket, string $due_date): ?array
    {
        $opening_date = (string) ($ticket->fields['date'] ?? '');
        if ($opening_date === '') {
            return null;
        }

        $current_date = date('Y-m-d H:i:s');
        $currenttime = 0;
        $totaltime = 0;
        $waitingtime = 0;

        $ola_id = (int) ($ticket->fields['olas_id_tto'] ?? 0);
        if ($ola_id > 0) {
            $ola = new OLA();
            if ($ola->getFromDB($ola_id)) {
                $currenttime = $ola->getActiveTimeBetween($opening_date, $current_date);
                $totaltime = $ola->getActiveTimeBetween($opening_date, $due_date);
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
                $currenttime = $calendar->getActiveTimeBetween($opening_date, $current_date);
                $totaltime = $calendar->getActiveTimeBetween($opening_date, $due_date);
            } else {
                $currenttime = strtotime($current_date) - strtotime($opening_date);
                $totaltime = strtotime($due_date) - strtotime($opening_date);
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

    private static function resolveProgressColor(
        int $percentage,
        int $totaltime,
        int $currenttime
    ): string {
        $less_warn_limit = 0;
        $less_warn = 0;
        if (($_SESSION['glpiduedatewarning_unit'] ?? '') === '%') {
            $less_warn_limit = (int) ($_SESSION['glpiduedatewarning_less'] ?? 0);
            $less_warn = 100 - $percentage;
        } elseif (($_SESSION['glpiduedatewarning_unit'] ?? '') === 'hour') {
            $less_warn_limit = (int) ($_SESSION['glpiduedatewarning_less'] ?? 0) * HOUR_TIMESTAMP;
            $less_warn = $totaltime - $currenttime;
        } elseif (($_SESSION['glpiduedatewarning_unit'] ?? '') === 'day') {
            $less_warn_limit = (int) ($_SESSION['glpiduedatewarning_less'] ?? 0) * DAY_TIMESTAMP;
            $less_warn = $totaltime - $currenttime;
        }

        $less_crit_limit = 0;
        $less_crit = 0;
        if (($_SESSION['glpiduedatecritical_unit'] ?? '') === '%') {
            $less_crit_limit = (int) ($_SESSION['glpiduedatecritical_less'] ?? 0);
            $less_crit = 100 - $percentage;
        } elseif (($_SESSION['glpiduedatecritical_unit'] ?? '') === 'hour') {
            $less_crit_limit = (int) ($_SESSION['glpiduedatecritical_less'] ?? 0) * HOUR_TIMESTAMP;
            $less_crit = $totaltime - $currenttime;
        } elseif (($_SESSION['glpiduedatecritical_unit'] ?? '') === 'day') {
            $less_crit_limit = (int) ($_SESSION['glpiduedatecritical_less'] ?? 0) * DAY_TIMESTAMP;
            $less_crit = $totaltime - $currenttime;
        }

        $ok_color = (string) ($_SESSION['glpiduedateok_color'] ?? '#2fb344');
        if ($less_crit < $less_crit_limit) {
            return (string) ($_SESSION['glpiduedatecritical_color'] ?? '#d63939');
        }

        if ($less_warn < $less_warn_limit) {
            return (string) ($_SESSION['glpiduedatewarning_color'] ?? '#de5d06');
        }

        return $ok_color;
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
}
