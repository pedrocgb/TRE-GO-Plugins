<?php

/**
 * -------------------------------------------------------------------------
 * tregoplugins plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2026 Pedro Henrique Cesar
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 */

/** @phpstan-ignore theCodingMachineSafe.function */
define('PLUGIN_TREGOPLUGINS_VERSION', '2.1.0');

/** @phpstan-ignore theCodingMachineSafe.function */
define('PLUGIN_TREGOPLUGINS_MIN_GLPI_VERSION', '10.0.0');

/** @phpstan-ignore theCodingMachineSafe.function */
define('PLUGIN_TREGOPLUGINS_MAX_GLPI_VERSION', '11.9.99');

require_once __DIR__ . '/src/CategoryConfig.php';
require_once __DIR__ . '/src/CategoryForm.php';
require_once __DIR__ . '/src/KbVisibilityConfig.php';
require_once __DIR__ . '/src/KbVisibilityGuard.php';
require_once __DIR__ . '/src/MainProfile.php';
require_once __DIR__ . '/src/OlaBusinessTimeService.php';
require_once __DIR__ . '/src/OlaProgressService.php';
require_once __DIR__ . '/src/OlaReport.php';
require_once __DIR__ . '/src/OlaReportRepository.php';
require_once __DIR__ . '/src/SetupMenu.php';
require_once __DIR__ . '/src/SolutionForm.php';
require_once __DIR__ . '/src/TicketAutomation.php';
require_once __DIR__ . '/src/TicketDispatchConfig.php';
require_once __DIR__ . '/src/TicketDispatchProfile.php';
require_once __DIR__ . '/src/TicketDispatchRuleReplay.php';
require_once __DIR__ . '/src/TicketDispatchEligibility.php';
require_once __DIR__ . '/src/TicketDispatchService.php';
require_once __DIR__ . '/src/TicketDispatchTimelineAction.php';

/**
 * Init hooks of the plugin.
 */
function plugin_init_tregoplugins(): void
{
    global $DB, $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CSRF_COMPLIANT]['tregoplugins'] = true;

    // "Disabled means not called": the ticket-dispatch runtime hooks and
    // assets are only registered when the module is turned on in Setup, so
    // a disabled module has zero footprint in the ticket creation/timeline
    // code paths. Guarded by tableExists() because plugin_init can run
    // before this module's install() has created its config table.
    $ticket_dispatch_enabled = $DB instanceof DBmysql
        && $DB->tableExists(PluginTregopluginsTicketDispatchConfig::TABLE)
        && PluginTregopluginsTicketDispatchConfig::isEnabled();

    $css_files = ['public/tregoplugins.css', 'public/ola-report.css'];
    $js_files  = ['public/tregoplugins-ticket-list.js'];
    if ($ticket_dispatch_enabled) {
        $css_files[] = 'public/css/ticket-dispatch.css';
        $js_files[]  = 'public/js/ticket-dispatch.js';
    }
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_CSS]['tregoplugins'] = $css_files;
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['tregoplugins'] = $js_files;

    $PLUGIN_HOOKS['menu_toadd']['tregoplugins'] = [
        'management' => [PluginTregopluginsOlaReport::class],
        'config'      => [PluginTregopluginsSetupMenu::class],
    ];

    // Single shared "TRE-GO" profile tab: every module's right is a row in
    // PluginTregopluginsMainProfile's matrix, instead of each module
    // registering its own same-named tab (which used to render as three
    // separate "TRE-GO" tabs).
    Plugin::registerClass(
        PluginTregopluginsMainProfile::class,
        ['addtabon' => Profile::class]
    );

    if ($ticket_dispatch_enabled) {
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::POST_PREPAREADD]['tregoplugins']['Ticket']
            = 'plugin_tregoplugins_on_ticket_dispatch_post_prepareadd';
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::TIMELINE_ACTIONS]['tregoplugins']
            = [PluginTregopluginsTicketDispatchTimelineAction::class, 'render'];
    }

    // Runtime workaround for the GLPI KnowbaseItem visibility bug (see
    // src/KbVisibilityGuard.php for the full explanation).
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::POST_INIT]['tregoplugins']
        = [PluginTregopluginsKbVisibilityGuard::class, 'boot'];

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['Ticket']
        = 'plugin_tregoplugins_on_ticket_add';
    $PLUGIN_HOOKS['item_update']['tregoplugins']['Ticket']
        = 'plugin_tregoplugins_on_ticket_update';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_UPDATE]['tregoplugins']['Ticket']
        = 'plugin_tregoplugins_on_ticket_pre_update';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['Group_Ticket']
        = 'plugin_tregoplugins_on_ticket_group_add';
    $PLUGIN_HOOKS['item_update']['tregoplugins']['Group_Ticket']
        = 'plugin_tregoplugins_on_ticket_group_update';
    $PLUGIN_HOOKS['item_delete']['tregoplugins']['Group_Ticket']
        = 'plugin_tregoplugins_on_ticket_group_delete';
    $PLUGIN_HOOKS['item_purge']['tregoplugins']['Group_Ticket']
        = 'plugin_tregoplugins_on_ticket_group_delete';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['Ticket_User']
        = 'plugin_tregoplugins_on_ticket_assigned_actor_add';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['Supplier_Ticket']
        = 'plugin_tregoplugins_on_ticket_assigned_actor_add';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_ADD]['tregoplugins']['ITILSolution']
        = 'plugin_tregoplugins_on_itilsolution_pre_add';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_FORM]['tregoplugins']
        = [PluginTregopluginsSolutionForm::class, 'preItemForm'];

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['ITILCategory']
        = 'plugin_tregoplugins_on_itilcategory_save';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_UPDATE]['tregoplugins']['ITILCategory']
        = 'plugin_tregoplugins_on_itilcategory_save';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_PURGE]['tregoplugins']['ITILCategory']
        = 'plugin_tregoplugins_on_itilcategory_purge';

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::POST_ITEM_FORM]['tregoplugins']
        = [PluginTregopluginsCategoryForm::class, 'postItemForm'];
}

function plugin_tregoplugins_on_ticket_dispatch_post_prepareadd(CommonDBTM $item): void
{
    PluginTregopluginsTicketDispatchService::normalizeGroupOnCreation($item);
}

function plugin_tregoplugins_on_ticket_add(CommonDBTM $item): void
{
    PluginTregopluginsTicketAutomation::handleTicketCreated($item);
    PluginTregopluginsOlaReportRepository::handleTicketCreated($item);
}

function plugin_tregoplugins_on_ticket_update(CommonDBTM $item): void
{
    PluginTregopluginsOlaReportRepository::handleTicketUpdated($item);
}

function plugin_tregoplugins_on_ticket_pre_update(CommonDBTM $item): void
{
    PluginTregopluginsTicketAutomation::prepareTicketClosure($item);
}

function plugin_tregoplugins_on_ticket_group_add(CommonDBTM $item): void
{
    PluginTregopluginsOlaReportRepository::handleGroupAssignment($item);
    PluginTregopluginsTicketAutomation::restartOlaTtoForGroupAssignment($item);
}

function plugin_tregoplugins_on_ticket_group_update(CommonDBTM $item): void
{
    PluginTregopluginsOlaReportRepository::handleGroupChange($item);
    PluginTregopluginsTicketAutomation::restartOlaTtoForGroupChange($item);
}

function plugin_tregoplugins_on_ticket_group_delete(CommonDBTM $item): void
{
    PluginTregopluginsOlaReportRepository::handleGroupRemoval($item);
}

function plugin_tregoplugins_on_ticket_assigned_actor_add(CommonDBTM $item): void
{
    PluginTregopluginsOlaReportRepository::handleTechnicianAssignment($item);
    PluginTregopluginsTicketAutomation::markOlaTtoAssigned($item);
}

function plugin_tregoplugins_on_itilsolution_pre_add(CommonDBTM $item): void
{
    PluginTregopluginsTicketAutomation::prepareSolutionCreation($item);
}

function plugin_tregoplugins_on_itilcategory_save(CommonDBTM $item): void
{
    PluginTregopluginsCategoryConfig::saveFromCategory($item);
}

function plugin_tregoplugins_on_itilcategory_purge(CommonDBTM $item): void
{
    PluginTregopluginsCategoryConfig::deleteForCategory($item);
}

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

function plugin_tregoplugins_do_install(): bool
{
    PluginTregopluginsCategoryConfig::install();
    PluginTregopluginsKbVisibilityConfig::install();
    PluginTregopluginsOlaBusinessTimeService::install();
    PluginTregopluginsOlaReportRepository::install();
    PluginTregopluginsOlaReport::installRights();
    PluginTregopluginsKbVisibilityConfig::installRights();
    PluginTregopluginsTicketDispatchConfig::install();
    PluginTregopluginsTicketDispatchService::install();
    PluginTregopluginsTicketDispatchConfig::installRights();
    PluginTregopluginsTicketDispatchProfile::installRights();

    return true;
}

function plugin_tregoplugins_do_uninstall(): bool
{
    PluginTregopluginsTicketDispatchProfile::uninstallRights();
    PluginTregopluginsTicketDispatchConfig::uninstallRights();
    PluginTregopluginsTicketDispatchService::uninstall();
    PluginTregopluginsTicketDispatchConfig::uninstall();
    PluginTregopluginsKbVisibilityConfig::uninstallRights();
    PluginTregopluginsOlaReport::uninstallRights();
    PluginTregopluginsOlaReportRepository::uninstall();
    PluginTregopluginsOlaBusinessTimeService::uninstall();
    PluginTregopluginsKbVisibilityConfig::uninstall();
    PluginTregopluginsCategoryConfig::uninstall();

    return true;
}

/**
 * Plugin metadata.
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_tregoplugins(): array
{
    return [
        'name'         => 'TRE-GO Master Plugin',
        'version'      => PLUGIN_TREGOPLUGINS_VERSION,
        'author'       => 'Pedro Henrique Cesar',
        'license'      => 'MIT',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TREGOPLUGINS_MIN_GLPI_VERSION,
                'max' => PLUGIN_TREGOPLUGINS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_tregoplugins_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_TREGOPLUGINS_MIN_GLPI_VERSION, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_TREGOPLUGINS_MAX_GLPI_VERSION, 'ge')
    ) {
        echo sprintf(
            'Este plugin requer GLPI >= %s e < %s.',
            PLUGIN_TREGOPLUGINS_MIN_GLPI_VERSION,
            PLUGIN_TREGOPLUGINS_MAX_GLPI_VERSION
        );

        return false;
    }

    return true;
}

function plugin_tregoplugins_check_config(bool $verbose = false): bool
{
    return true;
}
