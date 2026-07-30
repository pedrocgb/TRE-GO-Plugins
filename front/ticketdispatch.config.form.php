<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsTicketDispatchConfig::$rightname, UPDATE);

$config = new PluginTregopluginsTicketDispatchConfig();

if (isset($_POST['update'])) {
    // CSRF is already validated globally by inc/includes.php for every
    // non-AJAX POST; calling Session::checkCSRF() again here always fails
    // because the (single-use) token was already consumed by that check.
    $config->check($_POST['id'], UPDATE);
    $config->update($_POST);
    Html::back();
}

Html::displayNotFoundError();
