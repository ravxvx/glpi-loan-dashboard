<?php

namespace GlpiPlugin\Loandashboard;

use CommonGLPI;
use Session;

/**
 * Menu entry: Tools > Loan Dashboard.
 */
class Menu extends CommonGLPI
{

    public static function getTypeName($nb = 0)
    {
        return 'Loan Dashboard';
    }

    public static function getMenuName()
    {
        return 'Loan Dashboard';
    }

    public static function getIcon()
    {
        return 'ti ti-chart-pie';
    }

    public static function canView(): bool
    {
        return Session::haveRight('computer', READ);
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;
        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/loandashboard/front/dashboard.php';
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return [
            'title' => self::getMenuName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }
}
