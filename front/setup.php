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

echo "<div class='container-fluid'>";
echo "<div class='row g-3 justify-content-center'>";

echo "<div class='col-12 col-xl-6'>";
if (PluginTregopluginsKbVisibilityConfig::canView()) {
    PluginTregopluginsKbVisibilityConfig::displayTabContentForItem($config);
}
if (PluginTregopluginsChecklistConfig::canView()) {
    PluginTregopluginsChecklistConfig::displayTabContentForItem($config);
}
echo "</div>";

if (PluginTregopluginsTicketDispatchConfig::canView()) {
    echo "<div class='col-12 col-xl-6'>";
    PluginTregopluginsTicketDispatchConfig::displayTabContentForItem($config);
    echo "</div>";
}

echo "</div>";
echo "</div>";

Html::footer();
