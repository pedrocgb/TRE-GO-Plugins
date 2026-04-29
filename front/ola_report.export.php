<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsOlaReport::$rightname, READ);

$group_id = (int) ($_GET['groups_id'] ?? 0);
$date_from = export_date_param('date_from');
$date_to = export_date_param('date_to');
$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

if ($group_id <= 0 || $date_from === '' || $date_to === '' || !in_array($format, ['csv', 'xlsx'], true)) {
    Html::displayErrorAndDie('Parâmetros inválidos.');
}

$date_from_sql = $date_from . ' 00:00:00';
$date_to_sql = $date_to . ' 23:59:59';
$rows = PluginTregopluginsOlaReportRepository::getReportRows($group_id, $date_from_sql, $date_to_sql);
$group_name = PluginTregopluginsOlaReportRepository::getGroupName($group_id);
$filename = export_filename($group_name, $format);
$data = [export_headers()];
foreach ($rows as $row) {
    $data[] = export_row($row);
}

if ($format === 'xlsx') {
    export_xlsx($filename, $data);
}

export_csv($filename, $data);

function export_date_param(string $name): string
{
    $value = trim((string) ($_GET[$name] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function export_filename(string $group_name, string $extension): string
{
    $safe_group = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $group_name);
    if (!is_string($safe_group) || trim($safe_group) === '') {
        $safe_group = 'Grupo';
    }

    $safe_group = preg_replace('/[^A-Za-z0-9]+/', '_', $safe_group);
    $safe_group = trim((string) $safe_group, '_');
    if ($safe_group === '') {
        $safe_group = 'Grupo';
    }

    return 'Relatório_' . $safe_group . '_Tempo_Atendimento_' . date('Y-m-d') . '.' . $extension;
}

function export_headers(): array
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
        'Tempo Máximo para Atribuição',
        'Horário do Atendimento',
        'Excedeu Tempo de Atendimento',
        'Tempo excedido',
    ];
}

function export_row(array $row): array
{
    return [
        (string) (int) ($row['tickets_id'] ?? 0),
        (string) ($row['ticket_title'] ?? ''),
        (string) ($row['category_name'] ?? ''),
        (string) ($row['ticket_status_label'] ?? ''),
        export_datetime((string) ($row['ticket_opened_at'] ?? '')),
        (string) ($row['requester_name'] ?? ''),
        (string) ($row['technician_name'] ?? ''),
        (string) ($row['escalated_from_group_name'] ?? ''),
        export_datetime(export_escalation_date($row)),
        export_datetime((string) ($row['ola_due_at'] ?? '')),
        export_datetime((string) ($row['assigned_at'] ?? '')),
        export_ola_exceeded_label($row),
        export_elapsed($row),
    ];
}

function export_datetime(string $date): string
{
    return $date !== '' ? Html::convDateTime($date) : '';
}

function export_yes_no($value): string
{
    if ($value === null || $value === '') {
        return 'Não';
    }

    return (int) $value === 1 ? 'Sim' : 'Não';
}

function export_ola_exceeded_label(array $row): string
{
    $value = $row['ola_exceeded'] ?? null;
    if ($value !== null && $value !== '') {
        return export_yes_no($value);
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

function export_elapsed(array $row): string
{
    if (empty($row['assigned_at'])) {
        return 'Não atribuído';
    }

    return Html::timestampToString((int) ($row['working_seconds_to_assignment'] ?? 0), false);
}

function export_escalation_date(array $row): string
{
    if (trim((string) ($row['escalated_from_group_name'] ?? '')) === '') {
        return (string) ($row['ticket_opened_at'] ?? $row['pass_started_at'] ?? '');
    }

    return (string) ($row['pass_started_at'] ?? '');
}

function export_csv(string $filename, array $data): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    foreach ($data as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function export_xlsx(string $filename, array $data): void
{
    $tmp = tempnam(GLPI_TMP_DIR, 'tregoplugins_ola_');
    if ($tmp === false) {
        Html::displayErrorAndDie('Não foi possível gerar o arquivo XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        Html::displayErrorAndDie('Não foi possível abrir o arquivo XLSX.');
    }

    $zip->addFromString('[Content_Types].xml', xlsx_content_types());
    $zip->addFromString('_rels/.rels', xlsx_root_rels());
    $zip->addFromString('xl/workbook.xml', xlsx_workbook());
    $zip->addFromString('xl/_rels/workbook.xml.rels', xlsx_workbook_rels());
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsx_sheet($data));
    $zip->addFromString('xl/styles.xml', xlsx_styles());
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmp);
    unlink($tmp);
    exit;
}

function xlsx_sheet(array $data): string
{
    $rows = [];
    foreach ($data as $row_index => $row) {
        $cells = [];
        foreach (array_values($row) as $column_index => $value) {
            $cell = xlsx_column($column_index + 1) . ($row_index + 1);
            $style = $row_index === 0 ? ' s="1"' : '';
            $cells[] = '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t>' .
                htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8') .
                '</t></is></c>';
        }
        $rows[] = '<row r="' . ($row_index + 1) . '">' . implode('', $cells) . '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetViews><sheetView workbookViewId="0"/></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="15"/><sheetData>' . implode('', $rows) . '</sheetData>' .
        '</worksheet>';
}

function xlsx_column(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function xlsx_content_types(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>';
}

function xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';
}

function xlsx_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Relatório OLA" sheetId="1" r:id="rId1"/></sheets></workbook>';
}

function xlsx_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>';
}

function xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
        '</styleSheet>';
}
