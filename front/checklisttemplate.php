<?php

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(PluginTregopluginsChecklistTemplate::$rightname, READ);

Html::header(
    PluginTregopluginsChecklistTemplate::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    PluginTregopluginsSetupMenu::class
);

global $DB;

$can_create = PluginTregopluginsChecklistTemplate::canCreate();

echo "<div class='container-fluid'>";

if ($can_create) {
    echo "<div class='text-end mb-2'>";
    echo "<a class='btn btn-primary' href='" . Plugin::getWebDir('tregoplugins') . "/front/checklisttemplate.form.php'>";
    echo "<i class='ti ti-plus me-1'></i>" . __('Novo modelo de checklist', 'tregoplugins');
    echo "</a>";
    echo "</div>";
}

echo "<table class='table card-table'>";
echo "<thead><tr>";
echo "<th>" . __('Nome') . "</th>";
echo "<th>" . __('Versão', 'tregoplugins') . "</th>";
echo "<th>" . __('Política de conclusão', 'tregoplugins') . "</th>";
echo "<th>" . __('Itens', 'tregoplugins') . "</th>";
echo "<th>" . __('Ativo') . "</th>";
echo "</tr></thead><tbody>";

$iterator = $DB->request([
    'FROM'  => PluginTregopluginsChecklistTemplate::getTable(),
    'WHERE' => getEntitiesRestrictCriteria(PluginTregopluginsChecklistTemplate::getTable(), '', '', true),
    'ORDER' => 'name ASC',
]);

$policies = PluginTregopluginsChecklistTemplate::getPolicies();
$form_url = Plugin::getWebDir('tregoplugins') . '/front/checklisttemplate.form.php';

foreach ($iterator as $row) {
    $item_count = countElementsInTable(
        PluginTregopluginsChecklistTemplateItem::getTable(),
        ['checklisttemplates_id' => $row['id']]
    );

    echo "<tr>";
    echo "<td><a href='" . Html::entities_deep($form_url) . "?id=" . (int) $row['id'] . "'>"
        . Html::entities_deep($row['name']) . "</a></td>";
    echo "<td>" . (int) $row['version'] . "</td>";
    echo "<td>" . Html::entities_deep($policies[$row['completion_policy']] ?? $row['completion_policy']) . "</td>";
    echo "<td>" . $item_count . "</td>";
    echo "<td>" . ($row['is_active'] ? __('Sim') : __('Não')) . "</td>";
    echo "</tr>";
}

echo "</tbody></table>";
echo "</div>";

Html::footer();
