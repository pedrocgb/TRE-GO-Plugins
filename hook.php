<?php

/**
 * -------------------------------------------------------------------------
 * tregoplugins plugin for GLPI
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/src/CategoryConfig.php';

function plugin_tregoplugins_install(): bool
{
    return PluginTregopluginsCategoryConfig::install();
}

function plugin_tregoplugins_uninstall(): bool
{
    return PluginTregopluginsCategoryConfig::uninstall();
}
