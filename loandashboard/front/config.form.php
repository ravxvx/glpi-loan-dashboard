<?php

/**
 * Loan Dashboard - settings (Setup > Plugins > Loan Dashboard).
 */

use GlpiPlugin\Loandashboard\Menu;
use GlpiPlugin\Loandashboard\Settings;

Session::checkRight('config', UPDATE);

global $CFG_GLPI, $DB;
$self = $CFG_GLPI['root_doc'] . '/plugins/loandashboard/front/config.form.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Settings::save($_POST);
    Session::addMessageAfterRedirect(htmlescape('Loan Dashboard settings saved.'), true, INFO);
    Html::redirect($self);
}

Html::header('Loan Dashboard', '', 'config', 'plugin');

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$groups = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename']) as $g) {
    $groups[(int) $g['id']] = (string) $g['completename'];
}
$states = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_states', 'ORDER' => 'completename']) as $s) {
    $states[(int) $s['id']] = (string) $s['completename'];
}

$checks = static function (string $name, array $options, array $selected) use ($e): string {
    if ($options === []) {
        return '<div class="text-muted">None defined in GLPI yet.</div>';
    }
    $h = '<div class="d-flex flex-wrap gap-3">';
    foreach ($options as $id => $label) {
        $h .= '<label class="form-check"><input class="form-check-input" type="checkbox" name="' . $name . '[]" value="' . $id . '"'
            . (in_array($id, $selected, true) ? ' checked' : '') . '><span class="form-check-label">' . $e($label) . '</span></label>';
    }
    return $h . '</div>';
};

$v = Settings::all();

echo '<div class="container-fluid" style="max-width: 900px"><div class="card mt-3">'
    . '<div class="card-header"><h3 class="card-title"><i class="ti ti-chart-pie me-2"></i>Loan Dashboard - settings</h3>'
    . '<a class="ms-auto btn btn-sm btn-outline-primary" href="' . $e(Menu::getSearchURL()) . '">Open the dashboard</a></div>'
    . '<div class="card-body"><form method="post" action="' . $e($self) . '">';

echo '<h4>Loan pool (groups)</h4><p class="text-muted">Computers in these groups are counted as loan devices. Leave empty to count all computers.</p>'
    . $checks('groups', $groups, $v['groups'])
    . '<hr><h4>Statuses</h4><p class="text-muted">Which status means what. A status can be in only one list.</p>'
    . '<div class="mb-3"><label class="form-label"><span class="ld-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#206bc4"></span> On loan (device is with a student / teacher)</label>'
    . $checks('loaned', $states, $v['loaned']) . '</div>'
    . '<div class="mb-3"><label class="form-label"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#2fb344"></span> Available (ready to loan, e.g. at the IT office)</label>'
    . $checks('available', $states, $v['available']) . '</div>'
    . '<div class="mb-3"><label class="form-label"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#d63939"></span> Damaged / in repair</label>'
    . $checks('damaged', $states, $v['damaged']) . '</div>'
    . '<hr><h4>Privacy</h4>'
    . '<label class="form-check"><input class="form-check-input" type="checkbox" name="show_emails" value="1"' . ($v['show_emails'] ? ' checked' : '') . '>'
    . '<span class="form-check-label">Show full student e-mail addresses (otherwise shown as <code>cb***77@school.ie</code>)</span></label>'
    . '<div class="mt-4"><button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save</button></div>'
    . '</form></div></div></div>';

Html::footer();
