<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsChecklistBinding::$rightname, UPDATE);

$binding = new PluginTregopluginsChecklistBinding();

if (isset($_POST['add'])) {
    $binding->add($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $binding->check($_POST['id'], PURGE);
    $binding->delete($_POST, 1);
    Html::back();
}

Html::displayNotFoundError();
