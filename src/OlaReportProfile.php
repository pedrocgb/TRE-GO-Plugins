<?php

class PluginTregopluginsOlaReportProfile extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'Relatório OLA';
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
                'itemtype' => PluginTregopluginsOlaReport::class,
                'label'    => 'Permitir acesso ao "Relatório OLA"',
                'field'    => PluginTregopluginsOlaReport::$rightname,
                'rights'   => [READ => __('Read')],
            ],
        ];

        $profile->displayRightsChoiceMatrix(
            $rights,
            [
                'canedit' => $can_edit,
                'title'   => 'TRE-GO - Relatório OLA',
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
