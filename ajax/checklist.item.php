<?php

/**
 * JSON toggle/reopen endpoint for a single checklist item. Under ajax/ so
 * GLPI validates CSRF from the X-Glpi-Csrf-Token header (jQuery's
 * $(document).ajaxSend already sends it) instead of consuming the
 * single-use body token, letting rapid successive toggles on the same page
 * all pass CSRF (see inc/includes.php's GLPI_KEEP_CSRF_TOKEN branch).
 */
include __DIR__ . '/../../../inc/includes.php';

header('Content-Type: application/json');

function plugin_tregoplugins_checklist_item_respond(int $http_status, array $body): void
{
    http_response_code($http_status);
    echo json_encode($body);
    exit;
}

if (!Session::getLoginUserID()) {
    plugin_tregoplugins_checklist_item_respond(403, ['ok' => false, 'error' => 'forbidden']);
}

if (!PluginTregopluginsChecklistConfig::isEnabled()) {
    plugin_tregoplugins_checklist_item_respond(404, ['ok' => false, 'error' => 'not_found']);
}

if (!PluginTregopluginsChecklistProfile::canUpdateTaskChecklist()) {
    plugin_tregoplugins_checklist_item_respond(403, ['ok' => false, 'error' => 'forbidden']);
}

$items_id = (int) ($_POST['items_id'] ?? 0);
$lock_version = (int) ($_POST['lock_version'] ?? -1);
$checked_raw = $_POST['checked'] ?? null;

if ($items_id <= 0 || $lock_version < 0 || !in_array($checked_raw, ['0', '1'], true)) {
    plugin_tregoplugins_checklist_item_respond(400, ['ok' => false, 'error' => 'invalid_input']);
}

$note = isset($_POST['note']) ? (string) $_POST['note'] : null;
if ($note !== null && strlen($note) > 1000) {
    plugin_tregoplugins_checklist_item_respond(400, ['ok' => false, 'error' => 'note_too_long']);
}

$result = PluginTregopluginsChecklistItemService::toggle($items_id, $checked_raw === '1', $lock_version, $note);

$status_by_error = [
    PluginTregopluginsChecklistItemService::ERROR_NOT_FOUND => 404,
    PluginTregopluginsChecklistItemService::ERROR_FORBIDDEN => 403,
    PluginTregopluginsChecklistItemService::ERROR_CONFLICT  => 409,
    PluginTregopluginsChecklistItemService::ERROR_INACTIVE  => 400,
];

$status = $result['ok'] ? 200 : ($status_by_error[$result['error'] ?? ''] ?? 400);

plugin_tregoplugins_checklist_item_respond($status, $result);
