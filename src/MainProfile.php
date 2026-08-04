<?php

/**
 * The single "TRE-GO" tab on GLPI Profiles. Every module's right belongs in
 * this one matrix (Relatório OLA, Base de Conhecimento, Despacho de
 * chamados) instead of each module registering its own same-named tab,
 * which used to render as three separate "TRE-GO" tabs.
 */
class PluginTregopluginsMainProfile extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'TRE-GO';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getID() > 0) {
            // Plain text only: GLPI 11 escapes the tab title (unlike 10),
            // so an <img> here rendered as literal markup instead of an icon.
            return self::createTabEntry('TRE-GO');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile || $item->getID() <= 0) {
            return true;
        }

        $profile = new Profile();
        if (!$profile->can($item->getID(), READ)) {
            return true;
        }

        $can_edit = Session::haveRight(Profile::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($can_edit) {
            echo "<form method='post' action='" . Html::entities_deep(Profile::getFormURL()) . "'>";
        }

        $rights = [
            [
                'itemtype' => PluginTregopluginsOlaReport::class,
                'label'    => 'Permitir acesso ao "Relatório OLA"',
                'field'    => PluginTregopluginsOlaReport::$rightname,
                'rights'   => [READ => __('Read')],
            ],
            [
                'itemtype' => PluginTregopluginsKbVisibilityConfig::class,
                'label'    => 'Permitir gerenciar a "Correção de Visibilidade da Base de Conhecimento"',
                'field'    => PluginTregopluginsKbVisibilityConfig::$rightname,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => PluginTregopluginsTicketDispatchConfig::class,
                'label'    => __('Configurar o módulo de despacho de chamados (Setup)', 'tregoplugins'),
                'field'    => PluginTregopluginsTicketDispatchConfig::$rightname,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => PluginTregopluginsTicketDispatchProfile::class,
                'label'    => __('Permissão para ver o botão "Despachar chamado para Unidade Responsável"', 'tregoplugins'),
                'field'    => PluginTregopluginsTicketDispatchProfile::ACTION_RIGHTNAME,
                'rights'   => [READ => __('Read')],
            ],
            [
                'itemtype' => PluginTregopluginsChecklistProfile::class,
                'label'    => __('Gerenciar modelos de checklist e vínculos com modelos de tarefa', 'tregoplugins'),
                'field'    => PluginTregopluginsChecklistProfile::TEMPLATE_RIGHTNAME,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => PluginTregopluginsChecklistProfile::class,
                'label'    => __('Marcar itens de checklist nas tarefas dos chamados', 'tregoplugins'),
                'field'    => PluginTregopluginsChecklistProfile::TASKCHECKLIST_RIGHTNAME,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
        ];

        $profile->displayRightsChoiceMatrix(
            $rights,
            [
                'canedit' => $can_edit,
                'title'   => 'TRE-GO',
            ]
        );

        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";

        return true;
    }
}
