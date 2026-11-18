<?php

/**
 * Loan Dashboard - main page (Tools > Loan Dashboard) and CSV export.
 */

use GlpiPlugin\Loandashboard\Chart;
use GlpiPlugin\Loandashboard\Menu;
use GlpiPlugin\Loandashboard\Settings;
use GlpiPlugin\Loandashboard\Stats;

Session::checkRight('computer', READ);

global $CFG_GLPI;

$self = Menu::getSearchURL();

/* --------------------------------------------------------------- filters */
$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
$views = [
    'loaned'    => 'On loan',
    'overdue'   => 'On loan > ' . $days . ' days',
    'available' => 'Available',
    'damaged'   => 'Damaged',
    'other'     => 'Other status',
    'all'       => 'All loan devices',
];
$view = isset($views[$_GET['view'] ?? '']) ? (string) $_GET['view'] : 'loaned';

$periods = [
    '3'      => 'Last 3 months',
    '6'      => 'Last 6 months',
    '12'     => 'Last 12 months',
    '24'     => 'Last 24 months',
    'sy'     => 'This school year',
    'lsy'    => 'Last school year',
    'custom' => 'Custom dates',
];
$period = (string) ($_GET['period'] ?? ($_GET['months'] ?? '12'));
$period = isset($periods[$period]) ? $period : '12';

$now   = new DateTimeImmutable('today');
$valid = static function ($v): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
    return $d !== false ? $d : null;
};
// school year = 1 September - 30 June
$syStart = (int) $now->format('n') >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
switch ($period) {
    case 'sy':
        $from = new DateTimeImmutable($syStart . '-09-01');
        $to   = min($now, new DateTimeImmutable(($syStart + 1) . '-06-30'));
        break;
    case 'lsy':
        $from = new DateTimeImmutable(($syStart - 1) . '-09-01');
        $to   = new DateTimeImmutable($syStart . '-06-30');
        break;
    case 'custom':
        $from = $valid($_GET['from'] ?? '') ?? $now->modify('-11 months')->modify('first day of this month');
        $to   = $valid($_GET['to'] ?? '') ?? $now;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        break;
    default:
        $from = $now->modify('first day of this month')->modify('-' . ((int) $period - 1) . ' months');
        $to   = $now;
}
$periodLabel = $period === 'custom'
    ? $from->format('d M Y') . ' - ' . $to->format('d M Y')
    : $periods[$period] . ($period === 'sy' || $period === 'lsy' ? ' (' . $from->format('M Y') . ' - ' . $to->format('M Y') . ')' : '');

Stats::setFilters($_GET);
$f = Stats::filters();

// current filters, kept in every link
$params = array_filter([
    'group'    => $f['group'] ?: null,
    'model'    => $f['model'] ?: null,
    'location' => $f['location'] ?: null,
    'period'   => $period !== '12' ? $period : null,
    'from'     => $period === 'custom' ? $from->format('Y-m-d') : null,
    'to'       => $period === 'custom' ? $to->format('Y-m-d') : null,
    'days'     => $days !== 30 ? $days : null,
], static fn($v) => $v !== null);
$link = static fn(array $extra = []): string => $self . '?' . http_build_query(array_merge($params, $extra));

$listKind = $view === 'overdue' ? 'loaned' : $view;
$listMin  = $view === 'overdue' ? $days : 0;

/* ------------------------------------------------------------------ CSV */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Stats::listOf($listKind, $listMin);
    $file = 'loan-devices-' . $view . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, ['Device', 'Serial number', 'Model', 'Status', 'User', 'Student e-mail', 'Location', 'Status since', 'Days']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'], $r['serial'], $r['model'], $r['state'], $r['user'], $r['email'],
            $r['location'], $r['since'] !== '' ? substr($r['since'], 0, 10) : '', $r['days'] ?? '',
        ]);
    }
    fclose($out);
    return;
}

Html::header('Loan Dashboard', '', 'tools', Menu::class);

$e   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$t   = Stats::totals();
$mon = Stats::monthlyRange($from, $to);
$rows = Stats::listOf($listKind, $listMin);
$overdue = Stats::overdueCount($days);
$pool = Stats::pool();
$isAdmin = Session::haveRight('config', UPDATE);
$cfgUrl  = $CFG_GLPI['root_doc'] . '/plugins/loandashboard/front/config.form.php';
$compUrl = $CFG_GLPI['root_doc'] . '/front/computer.form.php?id=';

echo <<<CSS
<style>
.ld-wrap { max-width: 1400px; }
.ld-kpi { display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.ld-kpi a { text-decoration:none; color:inherit; }
.ld-kpi .card { border-left: 4px solid var(--c, #868e96); height: 100%; }
.ld-kpi .num { font-size: 2rem; font-weight: 700; line-height: 1.1; }
.ld-kpi .lbl { opacity: .7; }
.ld-kpi .card.active { box-shadow: 0 0 0 2px var(--c); }
.ld-donut { display:flex; align-items:center; gap: 18px; flex-wrap: wrap; }
.ld-legend > div { margin: 3px 0; white-space: nowrap; }
.ld-legend-row { display:flex; gap: 18px; flex-wrap: wrap; justify-content:center; margin-top: 4px; }
.ld-dot { display:inline-block; width:10px; height:10px; border-radius: 50%; margin-right: 6px; }
.ld-hbar { display:grid; grid-template-columns: minmax(90px, 38%) 1fr 36px; align-items:center; gap: 8px; margin: 6px 0; }
.ld-hbar-label { overflow:hidden; text-overflow: ellipsis; white-space: nowrap; }
.ld-hbar-track { background: var(--tblr-border-color, #e6e7e9); border-radius: 4px; height: 12px; overflow:hidden; }
.ld-hbar-fill { height: 100%; border-radius: 4px; }
.ld-hbar-value { text-align: right; font-weight: 600; }
.ld-table td, .ld-table th { white-space: nowrap; }
.ld-days-warn { color: #f59f00; font-weight: 600; }
.ld-days-bad { color: #d63939; font-weight: 600; }
.ld-filters .form-label { font-size: .75rem; margin-bottom: 2px; opacity: .75; }
.ld-chip { display:inline-flex; align-items:center; gap:4px; padding: 2px 10px; border-radius: 999px; background: var(--tblr-primary-lt, #e8f0fb); font-size: .8rem; }
@media print { .ld-noprint, .navbar, aside, header { display:none !important; } }
</style>
CSS;

echo '<div class="container-fluid ld-wrap mt-3">';

/* header */
$opt = static function (array $items, int|string $selected, string $all = '') use ($e): string {
    $h = $all !== '' ? '<option value="0">' . $e($all) . '</option>' : '';
    foreach ($items as $k => $label) {
        $h .= '<option value="' . $e($k) . '"' . ((string) $k === (string) $selected ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    return $h;
};

echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-3">'
    . '<h2 class="m-0 me-auto"><i class="ti ti-chart-pie me-2"></i>Loan Dashboard</h2>'
    . '<a class="btn btn-outline-secondary ld-noprint" href="' . $e($link(['view' => $view, 'export' => 'csv'])) . '"><i class="ti ti-file-spreadsheet me-1"></i>Export CSV</a>'
    . '<button class="btn btn-outline-secondary ld-noprint" onclick="window.print()"><i class="ti ti-printer me-1"></i>Print / PDF</button>';
if ($isAdmin) {
    echo '<a class="btn btn-outline-secondary ld-noprint" href="' . $e($cfgUrl) . '" title="Settings"><i class="ti ti-settings"></i></a>';
}
echo '</div>';

/* filter bar */
echo '<div class="card mb-3 ld-filters ld-noprint"><div class="card-body py-2">'
    . '<form method="get" action="' . $e($self) . '" class="row g-2 align-items-end" id="ld-filter-form">'
    . '<input type="hidden" name="view" value="' . $e($view) . '">'
    . '<div class="col-6 col-md-auto"><label class="form-label">Group</label><select name="group" class="form-select form-select-sm">'
    . $opt(Stats::groupOptions(), $f['group'], 'Loan pool (settings)') . '</select></div>'
    . '<div class="col-6 col-md-auto"><label class="form-label">Model</label><select name="model" class="form-select form-select-sm">'
    . $opt(Stats::options('model'), $f['model'], 'All models') . '</select></div>'
    . '<div class="col-6 col-md-auto"><label class="form-label">Location</label><select name="location" class="form-select form-select-sm">'
    . $opt(Stats::options('location'), $f['location'], 'All locations') . '</select></div>'
    . '<div class="col-6 col-md-auto"><label class="form-label">Period (charts)</label><select name="period" class="form-select form-select-sm" id="ld-period">'
    . $opt($periods, $period) . '</select></div>'
    . '<div class="col-6 col-md-auto ld-custom"><label class="form-label">From</label><input type="date" name="from" class="form-control form-control-sm" value="' . $e($from->format('Y-m-d')) . '"></div>'
    . '<div class="col-6 col-md-auto ld-custom"><label class="form-label">To</label><input type="date" name="to" class="form-control form-control-sm" value="' . $e($to->format('Y-m-d')) . '"></div>'
    . '<div class="col-6 col-md-auto"><label class="form-label">Overdue after (days)</label><input type="number" min="1" max="365" name="days" class="form-control form-control-sm" style="width:110px" value="' . (int) $days . '"></div>'
    . '<div class="col-auto"><button class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Apply</button>'
    . ' <a class="btn btn-sm btn-link" href="' . $e($self) . '">Reset</a></div>'
    . '</form></div></div>';

/* active filters */
$chips = [];
if ($f['group'] > 0) {
    $chips[] = 'Group: ' . (Stats::groupOptions()[$f['group']] ?? '#' . $f['group']);
}
if ($f['model'] > 0) {
    $chips[] = 'Model: ' . (Stats::options('model')[$f['model']] ?? '#' . $f['model']);
}
if ($f['location'] > 0) {
    $chips[] = 'Location: ' . (Stats::options('location')[$f['location']] ?? '#' . $f['location']);
}
$chips[] = 'Period: ' . $periodLabel;
echo '<div class="d-flex flex-wrap gap-2 mb-3">';
foreach ($chips as $c) {
    echo '<span class="ld-chip"><i class="ti ti-filter"></i>' . $e($c) . '</span>';
}
echo '</div>';

if (!Settings::isConfigured()) {
    echo '<div class="alert alert-warning"><div>The statuses are not set yet - open the <a href="' . $e($cfgUrl) . '">settings</a> and choose which status means <b>on loan</b>, <b>available</b> and <b>damaged</b>.</div></div>';
}
if ($pool === [] && Settings::ids('groups') !== []) {
    echo '<div class="alert alert-info"><div>No computers are in the selected loan group(s).</div></div>';
}

/* KPI cards */
$kpis = [
    ['all', 'Loan devices', $t['total'], '#495057', 'ti-device-laptop'],
    ['loaned', 'On loan', $t['loaned'], Chart::COLORS['loaned'], 'ti-user-share'],
    ['available', 'Available', $t['available'], Chart::COLORS['available'], 'ti-circle-check'],
    ['damaged', 'Damaged', $t['damaged'], Chart::COLORS['damaged'], 'ti-tool'],
];
echo '<div class="ld-kpi mb-3">';
foreach ($kpis as [$k, $label, $num, $color, $icon]) {
    echo '<a href="' . $e($link(['view' => $k])) . '"><div class="card' . ($view === $k ? ' active' : '') . '" style="--c:' . $color . '"><div class="card-body">'
        . '<div class="d-flex justify-content-between"><div class="lbl">' . $e($label) . '</div><i class="ti ' . $icon . '" style="color:' . $color . ';font-size:1.4rem"></i></div>'
        . '<div class="num">' . (int) $num . '</div></div></div></a>';
}
echo '<a href="' . $e($link(['view' => 'overdue'])) . '"><div class="card' . ($view === 'overdue' ? ' active' : '') . '" style="--c:#f76707"><div class="card-body">'
    . '<div class="d-flex justify-content-between"><div class="lbl">On loan &gt; ' . (int) $days . ' days</div><i class="ti ti-clock-exclamation" style="color:#f76707;font-size:1.4rem"></i></div>'
    . '<div class="num">' . (int) $overdue . '</div></div></div></a>';
echo '<div><div class="card" style="--c:#f59f00"><div class="card-body"><div class="d-flex justify-content-between"><div class="lbl">In use</div><i class="ti ti-gauge" style="color:#f59f00;font-size:1.4rem"></i></div>'
    . '<div class="num">' . (int) $t['utilisation'] . '%</div><div class="small text-muted">on loan of all working devices</div></div></div></div>';
echo '</div>';

/* status + model */
$statusItems = [];
$byState = [];
foreach ($pool as $c) {
    $byState[$c['state']] ??= ['n' => 0, 'kind' => $c['kind']];
    $byState[$c['state']]['n']++;
}
uasort($byState, static fn($a, $b) => $b['n'] <=> $a['n']);
$otherIdx = 0;
foreach ($byState as $name => $s) {
    $color = $s['kind'] !== 'other' ? Chart::COLORS[$s['kind']] : Chart::color(3 + $otherIdx++);
    $statusItems[] = [(string) $name, $s['n'], $color];
}

echo '<div class="row g-3 mb-3">'
    . '<div class="col-lg-5"><div class="card h-100"><div class="card-header"><h3 class="card-title">By status</h3></div><div class="card-body">'
    . Chart::donut($statusItems, (string) $t['total'], 'devices')
    . '</div></div></div>'
    . '<div class="col-lg-7"><div class="card h-100"><div class="card-header"><h3 class="card-title">By model</h3></div><div class="card-body">'
    . Chart::hbars(Stats::countBy('model'))
    . '</div></div></div></div>';

/* monthly */
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">Loans, returns and damage per month</h3>'
    . '<div class="card-subtitle ms-2 text-muted">' . $e($periodLabel) . ' - from the status history</div></div><div class="card-body">'
    . Chart::monthly($mon['labels'], [
        ['Loaned out', Chart::COLORS['loaned'], $mon['loaned']],
        ['Returned', Chart::COLORS['available'], $mon['available']],
        ['Damaged', Chart::COLORS['damaged'], $mon['damaged']],
    ])
    . '</div></div>';

/* device list */
echo '<div class="card mb-3"><div class="card-header flex-wrap gap-2">'
    . '<h3 class="card-title me-auto">' . $e($views[$view]) . ' <span class="badge bg-secondary-lt ms-1">' . count($rows) . '</span></h3>'
    . '<div class="btn-group ld-noprint" role="group">';
foreach ($views as $k => $label) {
    echo '<a class="btn btn-sm ' . ($k === $view ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . $e($link(['view' => $k])) . '">' . $e($label) . '</a>';
}
echo '</div><input type="search" class="form-control form-control-sm ld-noprint" style="max-width:220px" placeholder="Search..." oninput="ldFilter(this.value)"></div>';

if ($rows === []) {
    echo '<div class="card-body text-muted">No devices.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-hover table-striped card-table ld-table" id="ld-table"><thead><tr>'
        . '<th>Device</th><th>Serial number</th><th>Model</th><th>Status</th><th>User</th><th>Student e-mail</th><th>Location</th><th>Since</th><th>Days</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $cls  = '';
        if ($r['kind'] === 'loaned' && $r['days'] !== null) {
            $cls = $r['days'] > 2 * $days ? 'ld-days-bad' : ($r['days'] > $days ? 'ld-days-warn' : '');
        }
        echo '<tr><td><a href="' . $e($compUrl . $r['id']) . '">' . $e($r['name'] !== '' ? $r['name'] : '#' . $r['id']) . '</a></td>'
            . '<td>' . $e($r['serial']) . '</td><td>' . $e($r['model']) . '</td>'
            . '<td><span class="ld-dot" style="background:' . Chart::COLORS[$r['kind']] . '"></span>' . $e($r['state']) . '</td>'
            . '<td>' . $e($r['user']) . '</td><td>' . $e($r['email']) . '</td><td>' . $e($r['location']) . '</td>'
            . '<td>' . $e($r['since'] !== '' ? substr($r['since'], 0, 10) : '-') . '</td>'
            . '<td class="' . $cls . '">' . ($r['days'] !== null ? (int) $r['days'] : '-') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

/* most loaned */
if ($mon['top'] !== []) {
    $top = [];
    foreach ($mon['top'] as $id => $n) {
        $name = $pool[$id]['name'] ?? ('#' . $id);
        $serial = $pool[$id]['serial'] ?? '';
        $top[$name . ($serial !== '' ? ' (' . $serial . ')' : '')] = $n;
    }
    echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">Most loaned devices - ' . $e($periodLabel) . '</h3></div><div class="card-body">'
        . Chart::hbars($top)
        . '</div></div>';
}

echo '</div>';

echo <<<JS
<script>
(function () {
    var sel = document.getElementById('ld-period');
    function toggle() {
        document.querySelectorAll('.ld-custom').forEach(function (el) { el.style.display = sel && sel.value === 'custom' ? '' : 'none'; });
    }
    if (sel) { sel.addEventListener('change', toggle); toggle(); }
})();
function ldFilter(q) {
    q = (q || '').toLowerCase();
    document.querySelectorAll('#ld-table tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
    });
}
</script>
JS;

Html::footer();
