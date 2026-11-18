<?php

namespace GlpiPlugin\Loandashboard;

use Config;

/**
 * Which group is the loan pool and which statuses mean available / on loan / damaged.
 * Stored in GLPI's configuration table (context plugin:loandashboard).
 */
class Settings
{
    public const CONTEXT = 'plugin:loandashboard';

    public const KEYS = ['groups', 'available', 'loaned', 'damaged', 'show_emails'];

    /** Name patterns used to guess the settings on first install. */
    private const GUESS = [
        'available' => ['it office', 'available', 'in stock', 'ready', 'spare'],
        'loaned'    => ['in school', 'on loan', 'loaned', 'loan', 'with student', 'in use', 'assigned'],
        'damaged'   => ['damag', 'broken', 'repair', 'faulty'],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                $raw = Config::getConfigurationValues(self::CONTEXT);
            } catch (\Throwable $e) {
                $raw = [];
            }
            $v = [];
            foreach (['groups', 'available', 'loaned', 'damaged'] as $k) {
                $ids = json_decode((string) ($raw[$k] ?? '[]'), true);
                $v[$k] = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
            }
            $v['show_emails'] = (string) ($raw['show_emails'] ?? '0') === '1';
            self::$cache = $v;
        }
        return self::$cache;
    }

    public static function ids(string $key): array
    {
        return self::all()[$key] ?? [];
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (['groups', 'available', 'loaned', 'damaged'] as $k) {
            $ids = $input[$k] ?? [];
            $ids = is_array($ids) ? $ids : [$ids];
            $values[$k] = json_encode(array_values(array_filter(array_unique(array_map('intval', $ids)))));
        }
        $values['show_emails'] = !empty($input['show_emails']) ? '1' : '0';
        Config::setConfigurationValues(self::CONTEXT, $values);
        self::$cache = null;
    }

    public static function installDefaults(): void
    {
        global $DB;

        $current = Config::getConfigurationValues(self::CONTEXT);
        if (isset($current['loaned'])) {
            return; // already configured - keep the user's choice on updates
        }

        $groups = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups']) as $g) {
            if (stripos((string) $g['name'], 'loan') !== false) {
                $groups[] = (int) $g['id'];
            }
        }

        $states = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_states']) as $s) {
            $states[(int) $s['id']] = strtolower((string) $s['name']);
        }
        $picked = ['available' => [], 'loaned' => [], 'damaged' => []];
        foreach ($states as $id => $name) {
            foreach (['damaged', 'available', 'loaned'] as $kind) { // damaged first: "loan - damaged" is damaged
                foreach (self::GUESS[$kind] as $pattern) {
                    if (str_contains($name, $pattern)) {
                        $picked[$kind][] = $id;
                        continue 3;
                    }
                }
            }
        }

        Config::setConfigurationValues(self::CONTEXT, [
            'groups'      => json_encode($groups),
            'available'   => json_encode($picked['available']),
            'loaned'      => json_encode($picked['loaned']),
            'damaged'     => json_encode($picked['damaged']),
            'show_emails' => '0',
        ]);
        self::$cache = null;
    }

    public static function removeAll(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, self::KEYS);
    }

    public static function isConfigured(): bool
    {
        return self::ids('loaned') !== [] || self::ids('available') !== [];
    }
}
