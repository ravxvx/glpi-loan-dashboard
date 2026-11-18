<?php

/**
 * Loan Dashboard - GLPI 11 / 12
 * Dashboard and reports for school loan devices: totals, on loan / available / damaged,
 * charts by status and model, loans per month, list of devices currently on loan, CSV export.
 * Page: Tools > Loan Dashboard. Cards can also be added to GLPI's own dashboard.
 * Settings: Setup > Plugins > Loan Dashboard (wrench icon).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Loandashboard\Cards;
use GlpiPlugin\Loandashboard\Menu;

define('PLUGIN_LOANDASHBOARD_VERSION', '1.0.0');

function plugin_init_loandashboard()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['loandashboard'] = true;

    if (!Plugin::isPluginActive('loandashboard')) {
        return;
    }

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['loandashboard']     = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['loandashboard']      = ['tools' => Menu::class];
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['loandashboard'] = [Cards::class, 'register'];
}

function plugin_version_loandashboard()
{
    return [
        'name'         => 'Loan Dashboard',
        'version'      => PLUGIN_LOANDASHBOARD_VERSION,
        'author'       => 'School IT',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/ravxvx/glpi-loan-dashboard',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '12.99.99',
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_loandashboard_check_prerequisites()
{
    return true;
}

function plugin_loandashboard_check_config($verbose = false)
{
    return true;
}
