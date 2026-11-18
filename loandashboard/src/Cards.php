<?php

namespace GlpiPlugin\Loandashboard;

/**
 * Cards for GLPI's own dashboard (Home > Dashboard > "+" > group "Loan devices").
 */
class Cards
{
    private const GROUP = 'Loan devices';

    /** Hook "dashboard_cards": receives the cards of other plugins and adds ours. */
    public static function register($cards = null): array
    {
        $cards = is_array($cards) ? $cards : [];
        $p = self::class . '::';

        return array_merge($cards, [
            'plugin_loandashboard_summary' => [
                'widgettype' => ['summaryNumbers', 'multipleNumber'],
                'group'      => self::GROUP,
                'label'      => 'Loan devices - summary',
                'provider'   => $p . 'summary',
            ],
            'plugin_loandashboard_onloan' => [
                'widgettype' => ['bigNumber'],
                'group'      => self::GROUP,
                'label'      => 'Loan devices - on loan now',
                'provider'   => $p . 'onLoan',
            ],
            'plugin_loandashboard_available' => [
                'widgettype' => ['bigNumber'],
                'group'      => self::GROUP,
                'label'      => 'Loan devices - available',
                'provider'   => $p . 'available',
            ],
            'plugin_loandashboard_status' => [
                'widgettype' => ['donut', 'pie', 'halfdonut', 'hbar', 'bar'],
                'group'      => self::GROUP,
                'label'      => 'Loan devices by status',
                'provider'   => $p . 'byStatus',
            ],
            'plugin_loandashboard_model' => [
                'widgettype' => ['hbar', 'bar', 'donut', 'pie'],
                'group'      => self::GROUP,
                'label'      => 'Loan devices by model',
                'provider'   => $p . 'byModel',
            ],
            'plugin_loandashboard_monthly' => [
                'widgettype' => ['bars', 'lines', 'stackedbars', 'areas'],
                'group'      => self::GROUP,
                'label'      => 'Loans per month (last 12 months)',
                'provider'   => $p . 'monthly',
            ],
        ]);
    }

    private static function url(string $view = ''): string
    {
        return Menu::getSearchURL() . ($view !== '' ? '?view=' . $view : '');
    }

    public static function summary(array $params = []): array
    {
        $t = Stats::totals();
        return [
            'label' => $params['label'] ?? 'Loan devices',
            'icon'  => 'ti ti-device-laptop',
            'data'  => [
                ['number' => $t['total'], 'label' => 'Loan devices', 'url' => self::url('all')],
                ['number' => $t['loaned'], 'label' => 'On loan', 'url' => self::url('loaned')],
                ['number' => $t['available'], 'label' => 'Available', 'url' => self::url('available')],
                ['number' => $t['damaged'], 'label' => 'Damaged', 'url' => self::url('damaged')],
            ],
        ];
    }

    public static function onLoan(array $params = []): array
    {
        return ['number' => Stats::totals()['loaned'], 'label' => 'Devices on loan', 'icon' => 'ti ti-user-share', 'url' => self::url('loaned')];
    }

    public static function available(array $params = []): array
    {
        return ['number' => Stats::totals()['available'], 'label' => 'Devices available', 'icon' => 'ti ti-device-laptop', 'url' => self::url('available')];
    }

    private static function list(array $counts, array $params, string $icon): array
    {
        $data = [];
        foreach ($counts as $label => $n) {
            $data[] = ['number' => $n, 'label' => (string) $label, 'url' => self::url()];
        }
        return [
            'label' => $params['label'] ?? '',
            'icon'  => $icon,
            'data'  => $data !== [] ? $data : ['nodata' => true],
        ];
    }

    public static function byStatus(array $params = []): array
    {
        return self::list(Stats::countBy('state'), $params, 'ti ti-chart-donut');
    }

    public static function byModel(array $params = []): array
    {
        return self::list(Stats::countBy('model'), $params, 'ti ti-device-laptop');
    }

    public static function monthly(array $params = []): array
    {
        $m = Stats::monthly(12);
        return [
            'label' => $params['label'] ?? 'Loans per month',
            'icon'  => 'ti ti-chart-bar',
            'data'  => [
                'labels' => $m['labels'],
                'series' => [
                    ['name' => 'Loaned out', 'data' => $m['loaned']],
                    ['name' => 'Returned', 'data' => $m['available']],
                    ['name' => 'Damaged', 'data' => $m['damaged']],
                ],
            ],
        ];
    }
}
