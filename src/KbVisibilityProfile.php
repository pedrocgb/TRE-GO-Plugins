<?php

/**
 * Profile tab exposing the knowledge base visibility guard's own right
 * (plugin_tregoplugins_kbvisibility), so it can be granted or denied per
 * profile independently of the plugin's other modules (e.g. Relatório OLA).
 */
class PluginTregopluginsKbVisibilityProfile extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'Base de Conhecimento';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getID() > 0) {
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
                'itemtype' => PluginTregopluginsKbVisibilityConfig::class,
                'label'    => 'Permitir gerenciar a "Correção de Visibilidade da Base de Conhecimento"',
                'field'    => PluginTregopluginsKbVisibilityConfig::$rightname,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
        ];

        $profile->displayRightsChoiceMatrix(
            $rights,
            [
                'canedit' => $can_edit,
                'title'   => 'TRE-GO - Base de Conhecimento',
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
