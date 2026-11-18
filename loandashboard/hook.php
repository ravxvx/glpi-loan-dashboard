<?php

/**
 * Loan Dashboard - install / uninstall. No own tables: everything is read from GLPI.
 */

use GlpiPlugin\Loandashboard\Settings;

function plugin_loandashboard_install()
{
    Settings::installDefaults();
    return true;
}

function plugin_loandashboard_uninstall()
{
    Settings::removeAll();
    return true;
}
