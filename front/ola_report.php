<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsOlaReport::$rightname, READ);

PluginTregopluginsOlaReportRepository::ensureSchema();
PluginTregopluginsOlaBusinessTimeService::ensureSchema();

$web_dir = Plugin::getWebDir('tregoplugins');
$page_url = $web_dir . '/front/ola_report.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group_calendar'])) {
    Session::checkCSRF($_POST);

    $saved = PluginTregopluginsOlaBusinessTimeService::saveGroupCalendar(
        (int) ($_POST['calendar_groups_id'] ?? 0),
        (int) ($_POST['calendars_id'] ?? 0)
    );

    Session::addMessageAfterRedirect(
        $saved ? 'Calendário do grupo salvo.' : 'Não foi possível salvar o calendário do grupo.',
        false,
        $saved ? INFO : ERROR
    );
    Html::redirect($page_url);
}

$today = new DateTimeImmutable('now');
$date_from = self_get_date_param('date_from', $today->modify('-30 days')->format('Y-m-d'));
$date_to = self_get_date_param('date_to', $today->format('Y-m-d'));
$groups = PluginTregopluginsOlaReportRepository::getAvailableGroups();
$selected_group_id = (int) ($_GET['groups_id'] ?? ($groups[0]['id'] ?? 0));

$date_from_sql = $date_from . ' 00:00:00';
$date_to_sql = $date_to . ' 23:59:59';
$rows = $selected_group_id > 0
    ? PluginTregopluginsOlaReportRepository::getReportRows($selected_group_id, $date_from_sql, $date_to_sql)
    : [];
$calendars = PluginTregopluginsOlaReportRepository::getAvailableCalendars();
$selected_calendar_id = PluginTregopluginsOlaBusinessTimeService::getGroupCalendarId($selected_group_id);

Html::header('Relatório OLA', $_SERVER['PHP_SELF'], 'management', PluginTregopluginsOlaReport::class);
echo "<link rel='stylesheet' href='" . Html::entities_deep($web_dir) . "/public/ola-report.css?v=" . PLUGIN_TREGOPLUGINS_VERSION . "'>";

echo "<div class='tregoplugins-ola-report'>";
echo "  <div class='card mb-3'>";
echo "    <div class='card-body d-flex flex-column flex-lg-row justify-content-between gap-3'>";
echo "      <div>";
echo "        <h1 class='tregoplugins-ola-report-title'>Relatório OLA</h1>";
echo "        <p class='tregoplugins-ola-report-subtitle'>Histórico de Tempo de Atendimento por grupo, com calendário útil.</p>";
echo "      </div>";
echo "      <form class='row g-2 align-items-end' method='post' action='" . Html::entities_deep($page_url) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken(true)]);
echo "        <input type='hidden' name='calendar_groups_id' value='" . (int) $selected_group_id . "'>";
echo "        <div class='col-auto'><label class='form-label'>Calendário do grupo</label><select class='form-select' name='calendars_id'>";
echo "          <option value='0'>Fallback da OLA/entidade</option>";
foreach ($calendars as $calendar) {
    $id = (int) $calendar['id'];
    $selected = $id === $selected_calendar_id ? ' selected' : '';
    echo "<option value='{$id}'{$selected}>" . Html::entities_deep($calendar['name']) . "</option>";
}
echo "        </select></div>";
echo "        <div class='col-auto'><button class='btn btn-outline-primary' name='save_group_calendar' value='1'>Salvar</button></div>";
echo "      </form>";
echo "    </div>";
echo "  </div>";

echo "  <div class='card mb-3'>";
echo "    <div class='card-header'><strong>Filtros</strong></div>";
echo "    <div class='card-body'>";
echo "      <form method='get' action='" . Html::entities_deep($page_url) . "'>";
echo "        <div class='row g-3 align-items-end'>";
echo "          <div class='col-12 col-lg-4'><label class='form-label'>Grupo</label><select class='form-select' name='groups_id' required>";
echo "            <option value=''>Selecione...</option>";
foreach ($groups as $group) {
    $id = (int) $group['id'];
    $selected = $id === $selected_group_id ? ' selected' : '';
    echo "<option value='{$id}'{$selected}>" . Html::entities_deep($group['name']) . "</option>";
}
echo "          </select></div>";
echo "          <div class='col-6 col-lg-2'><label class='form-label'>Data inicial</label><input class='form-control' type='date' name='date_from' value='" . Html::entities_deep($date_from) . "' required></div>";
echo "          <div class='col-6 col-lg-2'><label class='form-label'>Data final</label><input class='form-control' type='date' name='date_to' value='" . Html::entities_deep($date_to) . "' required></div>";
echo "          <div class='col-12 col-lg-4 tregoplugins-ola-report-actions'>";
echo "            <button class='btn btn-primary' type='submit'>Aplicar</button>";
echo "            <a class='btn btn-outline-secondary' href='" . self_export_url($web_dir, $selected_group_id, $date_from, $date_to, 'csv') . "'>CSV</a>";
echo "            <a class='btn btn-outline-secondary' href='" . self_export_url($web_dir, $selected_group_id, $date_from, $date_to, 'xlsx') . "'>XLSX</a>";
echo "          </div>";
echo "        </div>";
echo "      </form>";
echo "    </div>";
echo "  </div>";

echo "  <div class='card'>";
echo "    <div class='card-header'><strong>Resultados</strong><span class='ms-2 text-muted'>" . count($rows) . " registro(s)</span></div>";
if (count($rows) === 0) {
    echo "<div class='tregoplugins-ola-report-empty'>Nenhum registro encontrado para o filtro selecionado.</div>";
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table table-hover table-striped tregoplugins-ola-report-table'>";
    echo "<thead><tr>";
    foreach (self_report_headers() as $header) {
        echo "<th>" . Html::entities_deep($header) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach (self_report_row($row) as $value) {
            echo "<td>" . Html::entities_deep($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}
echo "  </div>";
echo "</div>";

Html::footer();

function self_get_date_param(string $name, string $default): string
{
    $value = trim((string) ($_GET[$name] ?? $default));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default;
}

function self_export_url(string $web_dir, int $group_id, string $date_from, string $date_to, string $format): string
{
    return Html::entities_deep(
        $web_dir . '/front/ola_report.export.php?' . http_build_query([
            'groups_id'  => $group_id,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'format'     => $format,
        ])
    );
}

function self_report_headers(): array
{
    return [
        'ID',
        'Título',
        'Categoria',
        'Status',
        'Data de Abertura',
        'Solicitante',
        'Técnico Responsável',
        'Escalonado Por',
        'Data Escalonamento',
        'Excedeu Tempo de Atendimento',
        'Horário do Atendimento',
        'Tempo excedido',
    ];
}

function self_report_row(array $row): array
{
    return [
        (string) (int) ($row['tickets_id'] ?? 0),
        (string) ($row['ticket_title'] ?? ''),
        (string) ($row['category_name'] ?? ''),
        (string) ($row['ticket_status_label'] ?? ''),
        self_format_datetime((string) ($row['ticket_opened_at'] ?? '')),
        (string) ($row['requester_name'] ?? ''),
        (string) ($row['technician_name'] ?? ''),
        (string) ($row['escalated_from_group_name'] ?? ''),
        self_format_datetime(self_escalation_date($row)),
        self_ola_exceeded_label($row),
        self_format_datetime((string) ($row['assigned_at'] ?? '')),
        self_elapsed_label($row),
    ];
}

function self_format_datetime(string $date): string
{
    return $date !== '' ? Html::convDateTime($date) : '';
}

function self_yes_no($value): string
{
    if ($value === null || $value === '') {
        return 'Não';
    }

    return (int) $value === 1 ? 'Sim' : 'Não';
}

function self_ola_exceeded_label(array $row): string
{
    $value = $row['ola_exceeded'] ?? null;
    if ($value !== null && $value !== '') {
        return self_yes_no($value);
    }

    $due_at = trim((string) ($row['ola_due_at'] ?? ''));
    if ($due_at === '') {
        return 'Não';
    }

    $end_at = trim((string) ($row['assigned_at'] ?? $row['pass_ended_at'] ?? ''));
    if ($end_at === '') {
        $end_at = date('Y-m-d H:i:s');
    }

    return strtotime($end_at) > strtotime($due_at) ? 'Sim' : 'Não';
}

function self_elapsed_label(array $row): string
{
    if (empty($row['assigned_at'])) {
        return 'Não atribuído';
    }

    $seconds = (int) ($row['working_seconds_to_assignment'] ?? 0);
    return Html::timestampToString($seconds, false);
}

function self_escalation_date(array $row): string
{
    if (trim((string) ($row['escalated_from_group_name'] ?? '')) === '') {
        return (string) ($row['ticket_opened_at'] ?? $row['pass_started_at'] ?? '');
    }

    return (string) ($row['pass_started_at'] ?? '');
}
