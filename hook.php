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

if (!function_exists('plugin_tregoplugins_install')) {
    function plugin_tregoplugins_install($params = []): bool
    {
        return plugin_tregoplugins_do_install();
    }
}

if (!function_exists('plugin_tregoplugins_uninstall')) {
    function plugin_tregoplugins_uninstall(): bool
    {
        return plugin_tregoplugins_do_uninstall();
    }
}
