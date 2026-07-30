<?php

/**
 * Rights helper for the ticket-dispatch action right
 * (plugin_tregoplugins_ticketdispatch_action: see/use the "Despachar
 * chamado para Unidade Responsável" timeline button). The right is
 * displayed in the single shared "TRE-GO" profile tab owned by
 * PluginTregopluginsMainProfile, not by this class.
 */
class PluginTregopluginsTicketDispatchProfile extends CommonDBTM
{
    public const ACTION_RIGHTNAME = 'plugin_tregoplugins_ticketdispatch_action';

    public static function getTypeName($nb = 0): string
    {
        return __('Despacho de chamados', 'tregoplugins');
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
