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
        self::seedRight(self::TEMPLATE_RIGHTNAME);
        self::seedRight(self::TASKCHECKLIST_RIGHTNAME);
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::TEMPLATE_RIGHTNAME, self::TASKCHECKLIST_RIGHTNAME]);
    }

    /**
     * Seed a plugin right at 0 (no access) for every existing profile, so
     * new profiles created after install still get an explicit row. Mirrors
     * PluginTregopluginsTicketDispatchProfile::seedRight() (never granted to
     * managers automatically: admins must opt in explicitly per profile).
     */
    private static function seedRight(string $rightname): void
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

            $DB->insert(
                ProfileRight::getTable(),
                [
                    'profiles_id' => $profiles_id,
                    'name'        => $rightname,
                    'rights'      => 0,
                ]
            );
        }

        if (isset($GLPI_CACHE)) {
            $GLPI_CACHE->set('all_possible_rights', []);
        }
    }
}
