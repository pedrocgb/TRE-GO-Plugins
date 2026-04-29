<?php

/**
 * -------------------------------------------------------------------------
 * tregoplugins plugin for GLPI
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/src/CategoryConfig.php';
require_once __DIR__ . '/src/OlaBusinessTimeService.php';
require_once __DIR__ . '/src/OlaReport.php';
require_once __DIR__ . '/src/OlaReportRepository.php';

function plugin_tregoplugins_install(): bool
{
    PluginTregopluginsCategoryConfig::install();
    PluginTregopluginsOlaBusinessTimeService::install();
    PluginTregopluginsOlaReportRepository::install();
    PluginTregopluginsOlaReport::installRights();

    return true;
}

function plugin_tregoplugins_uninstall(): bool
{
    PluginTregopluginsOlaReport::uninstallRights();
    PluginTregopluginsOlaReportRepository::uninstall();
    PluginTregopluginsOlaBusinessTimeService::uninstall();
    PluginTregopluginsCategoryConfig::uninstall();

    return true;
}
