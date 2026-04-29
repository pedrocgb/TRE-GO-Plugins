<?php

class PluginTregopluginsOlaReport extends CommonGLPI
{
    public static $rightname = 'plugin_tregoplugins_olareport';

    public static function getTypeName($nb = 0): string
    {
        return 'Relatório OLA';
    }

    public static function getMenuName(): string
    {
        return self::getTypeName(1);
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('tregoplugins', $full) . '/front/ola_report.php';
    }

    public static function getIcon()
    {
        return 'ti ti-file-analytics';
    }

    public static function installRights(): void
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
                    ['profiles_id' => $profiles_id, 'name' => self::$rightname]
                ) > 0
            ) {
                continue;
            }

            $rights = self::isProfileManager($profiles_id) ? READ : 0;

            $DB->insert(
                ProfileRight::getTable(),
                [
                    'profiles_id' => $profiles_id,
                    'name'        => self::$rightname,
                    'rights'      => $rights,
                ]
            );
        }

        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set('all_possible_rights', []);
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::$rightname]);
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
