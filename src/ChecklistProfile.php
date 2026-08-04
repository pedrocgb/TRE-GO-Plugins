<?php

/**
 * Rights for the task-checklist module. Two separate rights, both displayed
 * in the single shared "TRE-GO" profile tab owned by PluginTregopluginsMainProfile:
 *  - plugin_tregoplugins_checklisttemplate (READ/UPDATE): manage checklist
 *    templates, their items and the TaskTemplate bindings, plus the module's
 *    Setup toggle (PluginTregopluginsChecklistConfig::$rightname reuses this
 *    same right).
 *  - plugin_tregoplugins_taskchecklist (READ/UPDATE): view/mark items on a
 *    materialized checklist inside a ticket's task timeline.
 */
class PluginTregopluginsChecklistProfile extends CommonDBTM
{
    public const TEMPLATE_RIGHTNAME = 'plugin_tregoplugins_checklisttemplate';
    public const TASKCHECKLIST_RIGHTNAME = 'plugin_tregoplugins_taskchecklist';

    public static function getTypeName($nb = 0): string
    {
        return __('Modelo de Checklist', 'tregoplugins');
    }

    public static function canViewTemplates(): bool
    {
        return Session::haveRight(self::TEMPLATE_RIGHTNAME, READ);
    }

    public static function canManageTemplates(): bool
    {
        return Session::haveRight(self::TEMPLATE_RIGHTNAME, UPDATE);
    }

    public static function canViewTaskChecklist(): bool
    {
        return Session::haveRight(self::TASKCHECKLIST_RIGHTNAME, READ);
    }

    public static function canUpdateTaskChecklist(): bool
    {
        return Session::haveRight(self::TASKCHECKLIST_RIGHTNAME, UPDATE);
    }

    public static function installRights(): void
    {
        self::seedRight(self::TEMPLATE_RIGHTNAME, true);
        self::seedRight(self::TASKCHECKLIST_RIGHTNAME, true);
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::TEMPLATE_RIGHTNAME, self::TASKCHECKLIST_RIGHTNAME]);
    }

    /**
     * Seed a plugin right for every existing profile, so new profiles
     * created after install still get an explicit row. Profile-manager
     * profiles (those with UPDATE on Profile::$rightname, e.g. Super-Admin)
     * get READ|UPDATE by default so the module is actually reachable right
     * after activation; every other profile starts at 0, same as
     * PluginTregopluginsTicketDispatchProfile::seedRight().
     */
    private static function seedRight(string $rightname, bool $grant_to_managers): void
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
