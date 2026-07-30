<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsTicketDispatchConfig::$rightname, UPDATE);

$config = new PluginTregopluginsTicketDispatchConfig();

if (isset($_POST['update'])) {
    Session::checkCSRF($_POST);
    $config->check($_POST['id'], UPDATE);
    $config->update($_POST);
    Html::back();
}

Html::displayNotFoundError();
