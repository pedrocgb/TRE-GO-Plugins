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
define('PLUGIN_TREGOPLUGINS_VERSION', '1.0.2');

/** @phpstan-ignore theCodingMachineSafe.function */
define('PLUGIN_TREGOPLUGINS_MIN_GLPI_VERSION', '10.0.0');

/** @phpstan-ignore theCodingMachineSafe.function */
define('PLUGIN_TREGOPLUGINS_MAX_GLPI_VERSION', '11.0.0');

require_once __DIR__ . '/src/CategoryConfig.php';
require_once __DIR__ . '/src/CategoryForm.php';
require_once __DIR__ . '/src/SolutionForm.php';
require_once __DIR__ . '/src/TicketAutomation.php';

/**
 * Init hooks of the plugin.
 */
function plugin_init_tregoplugins(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CSRF_COMPLIANT]['tregoplugins'] = true;

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['tregoplugins']['Ticket']
        = 'plugin_tregoplugins_on_ticket_add';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_UPDATE]['tregoplugins']['Ticket']
        = 'plugin_tregoplugins_on_ticket_pre_update';
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

function plugin_tregoplugins_on_ticket_add(CommonDBTM $item): void
{
    PluginTregopluginsTicketAutomation::handleTicketCreated($item);
}

function plugin_tregoplugins_on_ticket_pre_update(CommonDBTM $item): void
{
    PluginTregopluginsTicketAutomation::prepareTicketClosure($item);
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
        'name'         => 'TRE-GO ITIL Category Automation',
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
            'This plugin requires GLPI >= %s and < %s.',
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
