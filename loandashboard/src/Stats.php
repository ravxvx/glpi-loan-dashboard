<?php

namespace GlpiPlugin\Loandashboard;

use Glpi\Search\SearchOption;
use Group_Item;
use Search;

/**
 * Reads the loan pool from GLPI: computers in the loan group(s), their status,
 * and the status history (glpi_logs) for the monthly charts.
 */
class Stats
{
    private static ?array $pool = null;
    private static ?int $statusOption = null;

    /* -------------------------------------------------------------- pool */

    /** Computers in the loan pool (all computers when no group is set). */
    public static function pool(): array
    {
        global $DB;

        if (self::$pool !== null) {
            return self::$pool;
        }

        $where = [
            'glpi_computers.is_deleted'  => 0,
            'glpi_computers.is_template' => 0,
        ];
        if (function_exists('getEntitiesRestrictCriteria')) {
            $where += getEntitiesRestrictCriteria('glpi_computers', '', '', true);
        }

        $groups = Settings::ids('groups');
        if ($groups !== []) {
            $ids = [];
            foreach ($DB->request([
                'SELECT' => ['items_id'],
                'FROM'   => 'glpi_groups_items',
                'WHERE'  => [
                    'itemtype'  => 'Computer',
                    'groups_id' => $groups,
                    'type'      => class_exists(Group_Item::class) ? Group_Item::GROUP_TYPE_NORMAL : 1,
                ],
            ]) as $r) {
                $ids[(int) $r['items_id']] = true;
            }
            if ($ids === []) {
                return self::$pool = [];
            }
            $where['glpi_computers.id'] = array_keys($ids);
        }

        $rows = [];
        foreach ($DB->request([
            'SELECT'    => [
                'glpi_computers.id', 'glpi_computers.name', 'glpi_computers.serial',
                'glpi_computers.states_id', 'glpi_computers.users_id', 'glpi_computers.contact',
                'glpi_states.name AS state', 'glpi_computermodels.name AS model',
                'glpi_manufacturers.name AS manufacturer', 'glpi_locations.completename AS location',
                'glpi_users.name AS login', 'glpi_users.firstname', 'glpi_users.realname',
            ],
            'FROM'      => 'glpi_computers',
            'LEFT JOIN' => [
                'glpi_states'         => ['ON' => ['glpi_computers' => 'states_id', 'glpi_states' => 'id']],
                'glpi_computermodels' => ['ON' => ['glpi_computers' => 'computermodels_id', 'glpi_computermodels' => 'id']],
                'glpi_manufacturers'  => ['ON' => ['glpi_computers' => 'manufacturers_id', 'glpi_manufacturers' => 'id']],
                'glpi_locations'      => ['ON' => ['glpi_computers' => 'locations_id', 'glpi_locations' => 'id']],
                'glpi_users'          => ['ON' => ['glpi_computers' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'     => $where,
            'ORDER'     => 'glpi_computers.name',
        ]) as $r) {
            $user = trim(((string) $r['firstname']) . ' ' . ((string) $r['realname']));
            if ($user === '') {
                $user = (string) ($r['login'] ?? '');
            }
            if ($user === '') {
                $user = (string) ($r['contact'] ?? '');
            }
            $rows[(int) $r['id']] = [
                'id'        => (int) $r['id'],
                'name'      => (string) $r['name'],
                'serial'    => (string) $r['serial'],
                'states_id' => (int) $r['states_id'],
                'state'     => $r['state'] !== null ? (string) $r['state'] : 'No status',
                'model'     => trim(((string) $r['manufacturer']) . ' ' . ((string) $r['model'])) ?: 'Unknown model',
                'location'  => (string) ($r['location'] ?? ''),
                'user'      => $user,
                'kind'      => self::kind((int) $r['states_id']),
            ];
        }
        return self::$pool = $rows;
    }

    /** available | loaned | damaged | other */
    public static function kind(int $states_id): string
    {
        foreach (['loaned', 'available', 'damaged'] as $k) {
            if (in_array($states_id, Settings::ids($k), true)) {
                return $k;
            }
        }
        return 'other';
    }

    public static function totals(): array
    {
        $t = ['total' => 0, 'available' => 0, 'loaned' => 0, 'damaged' => 0, 'other' => 0];
        foreach (self::pool() as $c) {
            $t['total']++;
            $t[$c['kind']]++;
        }
        $usable = $t['total'] - $t['damaged'];
        $t['utilisation'] = $usable > 0 ? (int) round($t['loaned'] * 100 / $usable) : 0;
        return $t;
    }

    /** [label => count], sorted by count. */
    public static function countBy(string $field): array
    {
        $out = [];
        foreach (self::pool() as $c) {
            $key = $c[$field] !== '' ? $c[$field] : '-';
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    /* ----------------------------------------------------------- history */

    public static function statusOption(): int
    {
        if (self::$statusOption === null) {
            self::$statusOption = 31;
            try {
                $opts = class_exists(SearchOption::class)
                    ? SearchOption::getOptionsForItemtype('Computer')
                    : Search::getOptions('Computer');
                foreach ($opts as $id => $o) {
                    if (is_array($o) && ($o['table'] ?? '') === 'glpi_states') {
                        self::$statusOption = (int) $id;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // keep default
            }
        }
        return self::$statusOption;
    }

    /** "In School (3)" -> 3 */
    private static function stateIdFromLog(string $value): int
    {
        return preg_match('/\((\d+)\)\s*$/', $value, $m) ? (int) $m[1] : 0;
    }

    /** Fallback when the log only has the status name. */
    private static function stateIdByName(string $name): int
    {
        global $DB;
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_states']) as $s) {
                $map[strtolower(trim((string) $s['name']))] = (int) $s['id'];
                $map[strtolower(trim((string) $s['completename']))] = (int) $s['id'];
            }
        }
        return $map[strtolower(trim($name))] ?? 0;
    }

    /** Status changes of pool computers since $since (oldest first). */
    private static function statusChanges(string $since): array
    {
        global $DB;

        $ids = array_keys(self::pool());
        if ($ids === []) {
            return [];
        }
        $out    = [];
        $hasIds = $DB->fieldExists('glpi_logs', 'new_id'); // GLPI 11+: dropdown id stored in its own column
        foreach ($DB->request([
            'SELECT' => array_merge(['items_id', 'new_value', 'date_mod'], $hasIds ? ['new_id'] : []),
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'itemtype'         => 'Computer',
                'items_id'         => $ids,
                'id_search_option' => self::statusOption(),
                'date_mod'         => ['>=', $since],
            ],
            'ORDER'  => 'date_mod ASC',
        ]) as $r) {
            $sid = (int) ($r['new_id'] ?? 0);
            if ($sid === 0) {
                $sid = self::stateIdFromLog((string) $r['new_value']);
            }
            if ($sid === 0) {
                $sid = self::stateIdByName((string) $r['new_value']);
            }
            $out[] = [
                'items_id' => (int) $r['items_id'],
                'kind'     => self::kind($sid),
                'date'     => (string) $r['date_mod'],
            ];
        }
        return $out;
    }

    /**
     * Loans / returns / damage reports per month for the last $months months.
     * @return array{labels: string[], loaned: int[], available: int[], damaged: int[], top: array}
     */
    public static function monthly(int $months): array
    {
        $months = max(1, min(36, $months));
        $start  = new \DateTimeImmutable('first day of this month 00:00:00');
        $start  = $start->modify('-' . ($months - 1) . ' months');

        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = $start->modify("+{$i} months")->format('Y-m');
        }
        $series = ['loaned' => [], 'available' => [], 'damaged' => []];
        foreach ($series as $k => $_) {
            $series[$k] = array_fill_keys($keys, 0);
        }

        $perDevice = [];
        foreach (self::statusChanges($start->format('Y-m-d H:i:s')) as $ch) {
            $m = substr($ch['date'], 0, 7);
            if (isset($series[$ch['kind']][$m])) {
                $series[$ch['kind']][$m]++;
            }
            if ($ch['kind'] === 'loaned') {
                $perDevice[$ch['items_id']] = ($perDevice[$ch['items_id']] ?? 0) + 1;
            }
        }
        arsort($perDevice);

        $labels = array_map(static fn($k) => (new \DateTimeImmutable($k . '-01'))->format('M Y'), $keys);
        return [
            'labels'    => $labels,
            'loaned'    => array_values($series['loaned']),
            'available' => array_values($series['available']),
            'damaged'   => array_values($series['damaged']),
            'top'       => array_slice($perDevice, 0, 10, true),
        ];
    }

    /** Date of the last status change for each computer id (= "since" for the current status). */
    public static function since(array $ids): array
    {
        global $DB;

        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT'  => ['items_id', 'MAX' => 'date_mod AS last'],
            'FROM'    => 'glpi_logs',
            'WHERE'   => [
                'itemtype'         => 'Computer',
                'items_id'         => $ids,
                'id_search_option' => self::statusOption(),
            ],
            'GROUPBY' => 'items_id',
        ]) as $r) {
            $out[(int) $r['items_id']] = (string) $r['last'];
        }
        return $out;
    }

    /* ------------------------------------------------------------ e-mail */

    /** Student e-mail from the Fields plugin (field name containing "email"), per computer id. */
    public static function emails(array $ids): array
    {
        global $DB;

        if ($ids === []) {
            return [];
        }
        try {
            if (!$DB->tableExists('glpi_plugin_fields_fields') || !$DB->tableExists('glpi_plugin_fields_containers')) {
                return [];
            }
            foreach ($DB->request([
                'SELECT'     => ['glpi_plugin_fields_fields.name AS fname', 'glpi_plugin_fields_containers.name AS cname'],
                'FROM'       => 'glpi_plugin_fields_fields',
                'INNER JOIN' => [
                    'glpi_plugin_fields_containers' => [
                        'ON' => ['glpi_plugin_fields_fields' => 'plugin_fields_containers_id', 'glpi_plugin_fields_containers' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'glpi_plugin_fields_fields.name'          => ['LIKE', '%email%'],
                    'glpi_plugin_fields_containers.itemtypes' => ['LIKE', '%Computer%'],
                ],
                'ORDER'      => 'glpi_plugin_fields_fields.id ASC',
            ]) as $src) {
                $table = 'glpi_plugin_fields_computer' . $src['cname'] . 's';
                if (!$DB->tableExists($table) || !$DB->fieldExists($table, $src['fname'])) {
                    continue;
                }
                $out = [];
                foreach ($DB->request([
                    'SELECT' => ['items_id', $src['fname'] . ' AS email'],
                    'FROM'   => $table,
                    'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $ids],
                ]) as $r) {
                    $v = trim((string) $r['email']);
                    if ($v !== '') {
                        $out[(int) $r['items_id']] = Settings::all()['show_emails'] ? $v : self::mask($v);
                    }
                }
                return $out;
            }
        } catch (\Throwable $e) {
            // Fields plugin missing or different structure
        }
        return [];
    }

    /** jo***hn@school.ie */
    public static function mask(string $v): string
    {
        $at = strrpos($v, '@');
        if ($at === false) {
            return mb_substr($v, 0, 2) . '***';
        }
        $local = substr($v, 0, $at);
        return mb_strlen($local) <= 4
            ? mb_substr($local, 0, 1) . '***' . substr($v, $at)
            : mb_substr($local, 0, 2) . '***' . mb_substr($local, -2) . substr($v, $at);
    }

    /** Devices of one kind with user, e-mail and since date. */
    public static function listOf(string $kind): array
    {
        $rows = array_values(array_filter(self::pool(), static fn($c) => $kind === 'all' || $c['kind'] === $kind));
        $ids  = array_column($rows, 'id');
        $since  = self::since($ids);
        $emails = self::emails($ids);
        $now    = time();
        foreach ($rows as &$r) {
            $r['since'] = $since[$r['id']] ?? '';
            $r['days']  = $r['since'] !== '' ? (int) floor(($now - strtotime($r['since'])) / 86400) : null;
            $r['email'] = $emails[$r['id']] ?? '';
        }
        unset($r);
        usort($rows, static fn($a, $b) => ($b['days'] ?? -1) <=> ($a['days'] ?? -1));
        return $rows;
    }
}
