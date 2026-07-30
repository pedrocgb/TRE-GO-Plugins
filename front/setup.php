<?php

include __DIR__ . '/../../../inc/includes.php';

if (!PluginTregopluginsSetupMenu::canView()) {
    Html::displayRightError();
}

Html::header(
    PluginTregopluginsSetupMenu::getMenuName(),
    $_SERVER['PHP_SELF'],
    'config',
    PluginTregopluginsSetupMenu::class
);

$config = new Config();

if (PluginTregopluginsKbVisibilityConfig::canView()) {
    PluginTregopluginsKbVisibilityConfig::displayTabContentForItem($config);
}

if (PluginTregopluginsTicketDispatchConfig::canView()) {
    PluginTregopluginsTicketDispatchConfig::displayTabContentForItem($config);
}

Html::footer();
