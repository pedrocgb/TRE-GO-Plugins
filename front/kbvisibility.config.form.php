<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsKbVisibilityConfig::$rightname, UPDATE);

$config = new PluginTregopluginsKbVisibilityConfig();

if (isset($_POST['update'])) {
    Session::checkCSRF($_POST);
    $config->check($_POST['id'], UPDATE);
    $config->update($_POST);
    Html::back();
}

Html::displayNotFoundError();
