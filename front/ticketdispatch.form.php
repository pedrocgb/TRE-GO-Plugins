<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkLoginUser();
Session::checkCSRF($_POST);

$tickets_id = (int) ($_POST['tickets_id'] ?? 0);

if ($tickets_id <= 0) {
    Html::displayNotFoundError();
}

$ticket = new Ticket();
if (!$ticket->getFromDB($tickets_id)) {
    Html::displayNotFoundError();
}

$result = PluginTregopluginsTicketDispatchService::dispatch($ticket, (int) Session::getLoginUserID());

Session::addMessageAfterRedirect(
    $result['message'],
    false,
    $result['success'] ? INFO : ERROR
);

Html::redirect($ticket->getFormURLWithID($tickets_id));
