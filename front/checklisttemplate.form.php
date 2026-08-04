<?php

include __DIR__ . '/../../../inc/includes.php';

$template = new PluginTregopluginsChecklistTemplate();

if (isset($_POST['add'])) {
    $template->check(-1, CREATE, $_POST);
    $newid = $template->add($_POST);
    Html::redirect(Plugin::getWebDir('tregoplugins') . '/front/checklisttemplate.form.php?id=' . $newid);
} elseif (isset($_POST['update'])) {
    $template->check($_POST['id'], UPDATE);
    $template->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $template->check($_POST['id'], PURGE);
    $template->delete($_POST, 1);
    Html::redirect(Plugin::getWebDir('tregoplugins') . '/front/checklisttemplate.php');
} elseif (isset($_POST['additem'])) {
    $template->check($_POST['checklisttemplates_id'], UPDATE);
    $item = new PluginTregopluginsChecklistTemplateItem();
    $item->add($_POST);
    Html::back();
} elseif (isset($_POST['updateitem'])) {
    $item = new PluginTregopluginsChecklistTemplateItem();
    $item->getFromDB($_POST['id']);
    $template->check($item->fields['checklisttemplates_id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['deleteitem'])) {
    $item = new PluginTregopluginsChecklistTemplateItem();
    $item->getFromDB($_POST['id']);
    $template->check($item->fields['checklisttemplates_id'], UPDATE);
    $item->delete($_POST, 1);
    Html::back();
} elseif (isset($_POST['moveitem'])) {
    $item = new PluginTregopluginsChecklistTemplateItem();
    $item->getFromDB($_POST['id']);
    $template->check($item->fields['checklisttemplates_id'], UPDATE);
    $item->move((int) $_POST['direction']);
    Html::redirect(
        Plugin::getWebDir('tregoplugins') . '/front/checklisttemplate.form.php?id=' . $item->fields['checklisttemplates_id']
    );
}

Session::checkRight(PluginTregopluginsChecklistTemplate::$rightname, READ);

$id = (int) ($_GET['id'] ?? 0);
$is_new = $id <= 0;

if (!$is_new) {
    $template->check($id, READ);
}

Html::header(
    PluginTregopluginsChecklistTemplate::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    PluginTregopluginsSetupMenu::class
);

$can_edit = PluginTregopluginsChecklistTemplate::canUpdate();
$policies = PluginTregopluginsChecklistTemplate::getPolicies();
$form_url = Plugin::getWebDir('tregoplugins') . '/front/checklisttemplate.form.php';

echo "<div class='container-fluid'>";
echo "<div class='card mb-3'>";
echo "<form method='post' action='" . Html::entities_deep($form_url) . "'>";

echo "<div class='card-header'><span class='card-title'>"
    . ($is_new ? __('Novo modelo de checklist', 'tregoplugins') : Html::entities_deep($template->fields['name']))
    . "</span></div>";

echo "<div class='card-body'>";

echo "<div class='row mb-3'>";
echo "<div class='col-md-6'>";
echo "<label class='form-label'>" . __('Nome') . "</label>";
if ($can_edit) {
    echo "<input type='text' class='form-control' name='name' value='"
        . Html::entities_deep($template->fields['name'] ?? '') . "' required>";
} else {
    echo "<div>" . Html::entities_deep($template->fields['name'] ?? '') . "</div>";
}
echo "</div>";

echo "<div class='col-md-6'>";
echo "<label class='form-label'>" . __('Entidade') . "</label>";
if ($can_edit) {
    Entity::dropdown(['name' => 'entities_id', 'value' => $template->fields['entities_id'] ?? Session::getActiveEntity()]);
} else {
    echo "<div>" . Dropdown::getDropdownName(Entity::getTable(), $template->fields['entities_id'] ?? 0) . "</div>";
}
echo "</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>" . __('Descrição') . "</label>";
if ($can_edit) {
    echo "<textarea class='form-control' name='description' rows='2'>"
        . Html::entities_deep($template->fields['description'] ?? '') . "</textarea>";
} else {
    echo "<div>" . Html::entities_deep($template->fields['description'] ?? '') . "</div>";
}
echo "</div>";

echo "<div class='row mb-3'>";
echo "<div class='col-md-6'>";
echo "<label class='form-label'>" . __('Política de conclusão', 'tregoplugins') . "</label>";
if ($can_edit) {
    Dropdown::showFromArray('completion_policy', $policies, [
        'value' => $template->fields['completion_policy'] ?? PluginTregopluginsChecklistTemplate::POLICY_TRACK_ONLY,
    ]);
} else {
    echo "<div>" . Html::entities_deep($policies[$template->fields['completion_policy'] ?? ''] ?? '') . "</div>";
}
echo "</div>";

echo "<div class='col-md-3 pt-4'>";
echo "<div class='form-check form-switch'>";
echo "<input type='hidden' name='is_recursive' value='0'>";
echo "<input type='checkbox' class='form-check-input' id='is_recursive' name='is_recursive' value='1'"
    . (($template->fields['is_recursive'] ?? 0) ? " checked" : "") . ($can_edit ? "" : " disabled") . ">";
echo "<label class='form-check-label' for='is_recursive'>" . __('Recursivo') . "</label>";
echo "</div>";
echo "<div class='form-check form-switch'>";
echo "<input type='hidden' name='is_active' value='0'>";
echo "<input type='checkbox' class='form-check-input' id='is_active' name='is_active' value='1'"
    . (($template->fields['is_active'] ?? 1) ? " checked" : "") . ($can_edit ? "" : " disabled") . ">";
echo "<label class='form-check-label' for='is_active'>" . __('Ativo') . "</label>";
echo "</div>";
echo "</div>";

echo "<div class='col-md-3 pt-4'>";
echo "<div class='form-check form-switch'>";
echo "<input type='hidden' name='allow_manual_apply' value='0'>";
echo "<input type='checkbox' class='form-check-input' id='allow_manual_apply' name='allow_manual_apply' value='1'"
    . (($template->fields['allow_manual_apply'] ?? 0) ? " checked" : "") . ($can_edit ? "" : " disabled") . ">";
echo "<label class='form-check-label' for='allow_manual_apply'>"
    . __('Permitir aplicação manual', 'tregoplugins') . "</label>";
echo "</div>";
if (!$is_new) {
    echo "<div class='text-muted small mt-2'>" . sprintf(__('Versão atual: %d', 'tregoplugins'), (int) $template->fields['version']) . "</div>";
}
echo "</div>";
echo "</div>"; // row

echo "</div>"; // card-body

if ($can_edit) {
    echo "<div class='card-footer d-flex justify-content-between'>";
    if ($is_new) {
        echo "<span></span>";
        echo "<button type='submit' name='add' class='btn btn-primary'>" . _sx('button', 'Add') . "</button>";
    } else {
        echo Html::submit(_x('button', 'Delete permanently'), [
            'name'    => 'purge',
            'class'   => 'btn btn-outline-danger',
            'confirm' => __('Excluir definitivamente? Instâncias já materializadas em tarefas não são afetadas.', 'tregoplugins'),
        ]);
        echo Html::hidden('id', ['value' => $id]);
        echo "<button type='submit' name='update' class='btn btn-primary'>"
            . "<i class='ti ti-device-floppy me-1'></i>" . _sx('button', 'Save') . "</button>";
    }
    echo "</div>";
}

Html::closeForm();
echo "</div>"; // card

if (!$is_new) {
    $items = PluginTregopluginsChecklistTemplateItem::getAllItemsForTemplate($id);

    echo "<div class='card'>";
    echo "<div class='card-header'><span class='card-title'>" . __('Itens', 'tregoplugins') . "</span></div>";
    echo "<div class='card-body'>";

    echo "<table class='table'>";
    echo "<thead><tr>";
    echo "<th>" . __('Título') . "</th>";
    echo "<th>" . __('Instrução', 'tregoplugins') . "</th>";
    echo "<th>" . __('Obrigatório', 'tregoplugins') . "</th>";
    echo "<th>" . __('Ativo') . "</th>";
    if ($can_edit) {
        echo "<th>" . __('Ações') . "</th>";
    }
    echo "</tr></thead><tbody>";

    $last_index = count($items) - 1;
    foreach ($items as $index => $item_row) {
        echo "<tr>";
        echo "<td>" . Html::entities_deep($item_row['name']) . "</td>";
        echo "<td>" . Html::entities_deep($item_row['instructions'] ?? '') . "</td>";
        echo "<td>" . ($item_row['is_required'] ? __('Sim') : __('Não')) . "</td>";
        echo "<td>" . ($item_row['is_active'] ? __('Sim') : __('Não')) . "</td>";

        if ($can_edit) {
            echo "<td class='text-nowrap'>";

            if ($index > 0) {
                echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='d-inline'>";
                echo Html::hidden('id', ['value' => $item_row['id']]);
                echo Html::hidden('direction', ['value' => -1]);
                echo "<button type='submit' name='moveitem' class='btn btn-sm btn-outline-secondary'><i class='ti ti-arrow-up'></i></button>";
                Html::closeForm();
            }
            if ($index < $last_index) {
                echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='d-inline'>";
                echo Html::hidden('id', ['value' => $item_row['id']]);
                echo Html::hidden('direction', ['value' => 1]);
                echo "<button type='submit' name='moveitem' class='btn btn-sm btn-outline-secondary'><i class='ti ti-arrow-down'></i></button>";
                Html::closeForm();
            }

            echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='d-inline'>";
            echo Html::hidden('id', ['value' => $item_row['id']]);
            echo "<button type='submit' name='deleteitem' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i></button>";
            Html::closeForm();
            echo "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table>";

    if ($can_edit) {
        echo "<form method='post' action='" . Html::entities_deep($form_url) . "' class='row g-2 align-items-end'>";
        echo Html::hidden('checklisttemplates_id', ['value' => $id]);
        echo "<div class='col-md-4'><input type='text' class='form-control' name='name' placeholder='"
            . __('Novo item', 'tregoplugins') . "' required></div>";
        echo "<div class='col-md-4'><input type='text' class='form-control' name='instructions' placeholder='"
            . __('Instrução (opcional)', 'tregoplugins') . "'></div>";
        echo "<div class='col-md-2 form-check'>";
        echo "<input type='checkbox' class='form-check-input' id='new_is_required' name='is_required' value='1'>";
        echo "<label class='form-check-label' for='new_is_required'>" . __('Obrigatório', 'tregoplugins') . "</label>";
        echo "</div>";
        echo "<div class='col-md-2'><button type='submit' name='additem' class='btn btn-primary w-100'>"
            . "<i class='ti ti-plus'></i></button></div>";
        Html::closeForm();
    }

    echo "</div>"; // card-body
    echo "</div>"; // card
}

echo "</div>"; // container

Html::footer();
