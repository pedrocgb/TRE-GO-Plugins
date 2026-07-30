<?php

/**
 * Registers the single "TRE-GO Plugins" entry under the GLPI Setup menu
 * (Hooks::MENU_TOADD, 'config' category). front/setup.php is the one page
 * behind it and aggregates every module's own config section (each module
 * keeps its own showConfigForm()/right, this class only owns the menu
 * entry and the page shell).
 */
class PluginTregopluginsSetupMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('TRE-GO Plugins', 'tregoplugins');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function canView(): bool
    {
        return PluginTregopluginsKbVisibilityConfig::canView()
            || PluginTregopluginsTicketDispatchConfig::canView();
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('tregoplugins', $full) . '/front/setup.php';
    }

    public static function getIcon()
    {
        return 'ti ti-settings-automation';
    }
}
