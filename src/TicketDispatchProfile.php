<?php

/**
 * TRE-GO profile tab exposing the two ticket-dispatch rights:
 * - plugin_tregoplugins_ticketdispatch: view/edit the module's Setup config.
 * - plugin_tregoplugins_ticketdispatch_action: see and use the "Despachar
 *   chamado para Unidade Responsável" timeline button.
 *
 * Kept as its own class (rather than folded into TicketDispatchConfig) so
 * both rights share one seeding helper and one profile matrix, matching the
 * existing plugin convention of one *Profile class per feature.
 */
class PluginTregopluginsTicketDispatchProfile extends CommonDBTM
{
    public const ACTION_RIGHTNAME = 'plugin_tregoplugins_ticketdispatch_action';

    public static function getTypeName($nb = 0): string
    {
        return __('Despacho de chamados', 'tregoplugins');
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
                'itemtype' => PluginTregopluginsTicketDispatchConfig::class,
                'label'    => __('Configurar o módulo de despacho de chamados (Setup)', 'tregoplugins'),
                'field'    => PluginTregopluginsTicketDispatchConfig::$rightname,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Permissão para ver o botão "Despachar chamado para Unidade Responsável"', 'tregoplugins'),
                'field'    => self::ACTION_RIGHTNAME,
                'rights'   => [READ => __('Read')],
            ],
        ];

        $profile->displayRightsChoiceMatrix(
            $rights,
            [
                'canedit' => $can_edit,
                'title'   => 'TRE-GO - ' . self::getTypeName(),
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

    public static function canUseDispatchAction(): bool
    {
        return Session::haveRight(self::ACTION_RIGHTNAME, READ);
    }

    public static function installRights(): void
    {
        self::seedRight(self::ACTION_RIGHTNAME, false);
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::ACTION_RIGHTNAME]);
    }

    /**
     * Seed a plugin right at 0 (no access) for every existing profile,
     * except super-admin/configuration-manager profiles when $grant_to_managers
     * is true. Shared by both the config right and the action right so new
     * profiles created after install still get an explicit row.
     */
    public static function seedRight(string $rightname, bool $grant_to_managers): void
    {
        global $DB, $GLPI_CACHE;

        if (!$DB->tableExists(ProfileRight::getTable())) {
            return;
        }

        $profiles = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Profile::getTable(),
        ]);

        foreach ($profiles as $profile) {
            $profiles_id = (int) ($profile['id'] ?? 0);
            if ($profiles_id <= 0) {
                continue;
            }

            if (
                countElementsInTable(
                    ProfileRight::getTable(),
                    ['profiles_id' => $profiles_id, 'name' => $rightname]
                ) > 0
            ) {
                continue;
            }

            $rights = ($grant_to_managers && self::isProfileManager($profiles_id))
                ? (READ | UPDATE)
                : 0;

            $DB->insert(
                ProfileRight::getTable(),
                [
                    'profiles_id' => $profiles_id,
                    'name'        => $rightname,
                    'rights'      => $rights,
                ]
            );
        }

        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set('all_possible_rights', []);
        }
    }

    private static function isProfileManager(int $profiles_id): bool
    {
        global $DB;

        if ($profiles_id <= 0) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => Profile::$rightname,
            ],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return false;
        }

        $row = $iterator->current();
        return (((int) ($row['rights'] ?? 0)) & UPDATE) === UPDATE;
    }
}
