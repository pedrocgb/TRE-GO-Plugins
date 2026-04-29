<?php

include __DIR__ . '/../../../inc/includes.php';

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

require_once __DIR__ . '/../src/OlaBusinessTimeService.php';
require_once __DIR__ . '/../src/OlaProgressService.php';

$raw_ids = $_GET['ids'] ?? [];
if (is_string($raw_ids)) {
    $raw_ids = preg_split('/[,\s]+/', $raw_ids, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

$ticket_ids = array_values(array_unique(array_filter(
    array_map(
        static fn(mixed $value): int => max(0, (int) $value),
        (array) $raw_ids
    ),
    static fn(int $ticket_id): bool => $ticket_id > 0
)));

if (count($ticket_ids) > 200) {
    $ticket_ids = array_slice($ticket_ids, 0, 200);
}

$results = [];
foreach ($ticket_ids as $ticket_id) {
    $ticket = new Ticket();
    if (!$ticket->can($ticket_id, READ)) {
        continue;
    }

    $results[$ticket_id] = PluginTregopluginsOlaProgressService::renderProgressCell($ticket);
}

echo json_encode(
    ['results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
