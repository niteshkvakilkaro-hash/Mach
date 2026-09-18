<?php
/** Reports: overview, leads, jobs and revenue for any date range. Every table exports to CSV. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/models/reports.php';
$me = require_permission('reports.view');

$tabs = ['overview' => ['Overview', 'bi-speedometer2'], 'leads' => ['Leads', 'bi-person-lines-fill'], 'jobs' => ['Jobs & technicians', 'bi-person-gear'], 'revenue' => ['Revenue', 'bi-currency-rupee']];
if (!can('payments.view')) unset($tabs['revenue']);
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'overview';
$r = report_range($_GET);
$money = fn($v) => format_inr($v);
$int = fn($v) => number_format((int) $v);
$percent = fn($v) => number_format((float) $v, 1) . '%';

// ---------------------------------------------------------------- tables for the active tab
$tables = [];
if ($tab === 'overview') {
    $o = report_overview($r);
    $leadTl = report_leads_timeline($r);
    $tables['leads_timeline'] = ['title' => 'Leads over time', 'rows' => $leadTl, 'cols' => ['label' => ['Period'], 'leads' => ['Leads', $int], 'converted' => ['Converted', $int]]];
    if (can('payments.view')) {
        $revTl = report_revenue_timeline($r);
        $tables['revenue_timeline'] = ['title' => 'Collections over time', 'rows' => $revTl, 'cols' => ['label' => ['Period'], 'payments' => ['Payments', $int], 'collected' => ['Collected', $money]]];
    }
} elseif ($tab === 'leads') {
    $tables['by_source'] = ['title' => 'Source-wise leads', 'rows' => report_leads_by('source', $r)];
    $tables['by_service'] = ['title' => 'Service-wise leads', 'rows' => report_leads_by('service', $r)];
    $tables['by_staff'] = ['title' => 'Staff-wise leads', 'rows' => report_leads_by('staff', $r)];
    $tables['by_area'] = ['title' => 'Area-wise leads', 'rows' => report_leads_by('area', $r)];
    $tables['by_form'] = ['title' => 'Leads by form', 'rows' => report_leads_by('form', $r)];
    foreach ($tables as &$t) {
        $t['cols'] = ['label' => [''], 'leads' => ['Leads', $int], 'converted' => ['Converted', $int], 'conversion' => ['Conversion', $percent],
                      'completed' => ['Completed', $int], 'open_leads' => ['Still open', $int], 'lost' => ['Lost', $int]];
    }
    unset($t);
    $tables['by_source']['cols']['label'][0] = 'Source';
    $tables['by_service']['cols']['label'][0] = 'Service';
    $tables['by_staff']['cols']['label'][0] = 'Assigned to';
    $tables['by_area']['cols']['label'][0] = 'Area';
    $tables['by_form']['cols']['label'][0] = 'Form';
} elseif ($tab === 'jobs') {
    $tables['technicians'] = ['title' => 'Technician-wise jobs', 'rows' => report_technicians($r), 'cols' => [
        'label' => ['Technician'], 'jobs' => ['Jobs', $int], 'completed' => ['Completed', $int], 'completion' => ['Completion', $percent],
        'cancelled' => ['Cancelled', $int], 'open_jobs' => ['Open', $int], 'rescheduled' => ['Rescheduled', $int],
    ] + (can('payments.view') ? ['job_value' => ['Job value', $money], 'collected' => ['Cash collected', $money]] : [])];
    $tables['jobs_by_service'] = ['title' => 'Completed & cancelled jobs by service', 'rows' => report_jobs_by_service($r), 'cols' => [
        'label' => ['Service'], 'jobs' => ['Jobs', $int], 'completed' => ['Completed', $int], 'cancelled' => ['Cancelled', $int],
    ] + (can('payments.view') ? ['job_value' => ['Job value', $money], 'avg_value' => ['Avg. job', $money]] : [])];
    $tables['cancel_reasons'] = ['title' => 'Top cancellation reasons', 'rows' => report_cancel_reasons($r), 'cols' => ['label' => ['Reason'], 'jobs' => ['Jobs', $int]]];
} elseif ($tab === 'revenue') {
    $revTl = report_revenue_timeline($r);
    $tables['revenue_timeline'] = ['title' => 'Payment collection', 'rows' => $revTl, 'cols' => ['label' => ['Period'], 'payments' => ['Payments', $int], 'collected' => ['Collected', $money]]];
    $tables['revenue_by_service'] = ['title' => 'Revenue by service', 'rows' => report_revenue_by('service', $r), 'cols' => ['label' => ['Service'], 'payments' => ['Payments', $int], 'collected' => ['Collected', $money]]];
    $tables['revenue_by_method'] = ['title' => 'Revenue by payment method', 'rows' => report_revenue_by('method', $r), 'cols' => ['label' => ['Method'], 'payments' => ['Payments', $int], 'collected' => ['Collected', $money]]];
    $tables['revenue_by_collector'] = ['title' => 'Collected by', 'rows' => report_revenue_by('collector', $r), 'cols' => ['label' => ['Collected by'], 'payments' => ['Payments', $int], 'collected' => ['Collected', $money]]];
}

// ---------------------------------------------------------------- CSV export of one table
if (!empty($_GET['export'])) {
    require_permission('reports.export');
    $t = $tables[$_GET['export']] ?? null;
    if (!$t) {
        http_response_code(404);
        exit('Unknown report');
    }
    audit_log((int) $me['id'], 'exported', 'reports', null, "Exported report {$t['title']} ({$r['from']} to {$r['to']})");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . slugify($t['title']) . "-{$r['from']}-to-{$r['to']}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c[0] ?: 'Name', $t['cols']));
    $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    foreach ($t['rows'] as $row) {
        fputcsv($out, array_map(fn($k) => $safe($row[$k] ?? ''), array_keys($t['cols'])));
    }
    fclose($out);
    exit;
}

$presets = [
    'Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')], 'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Last 90 days' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')], 'This year' => [date('Y-01-01'), date('Y-m-d')],
];
$q = ['tab' => $tab, 'from' => $r['from'], 'to' => $r['to']];
$admin = ['title' => 'Reports', 'subtitle' => date('d M Y', strtotime($r['from'])) . ' – ' . date('d M Y', strtotime($r['to'])) . ' · ' . $r['days'] . ' day' . ($r['days'] === 1 ? '' : 's'), 'active' => 'reports'];
if ($tab === 'overview') $adminScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
require ROOT_PATH . '/includes/admin/header.php';

$renderTable = function (string $key, array $t) use ($q) {
    echo '<section class="panel mb-4"><div class="panel-head"><h2>' . e($t['title']) . '</h2>';
    if (can('reports.export') && $t['rows']) echo '<a class="btn btn-ghost btn-sm" href="' . e(admin_query_url('admin/reports', $q, ['export' => $key])) . '"><i class="bi bi-download"></i> CSV</a>';
    echo '</div>';
    if (!$t['rows']) { echo '<div class="empty-mini">No data in this period.</div></section>'; return; }
    echo '<div class="table-responsive"><table class="table admin-table mb-0"><thead><tr>';
    $i = 0;
    foreach ($t['cols'] as $c) echo '<th' . ($i++ ? ' class="text-end"' : '') . '>' . e($c[0]) . '</th>';
    echo '</tr></thead><tbody>';
    $totals = [];
    foreach ($t['rows'] as $row) {
        echo '<tr>';
        $i = 0;
        foreach ($t['cols'] as $k => $c) {
            $v = $row[$k] ?? '';
            if ($i && is_numeric($v) && !in_array($k, ['conversion', 'completion', 'avg_value'], true)) $totals[$k] = ($totals[$k] ?? 0) + $v;
            echo '<td' . ($i++ ? ' class="text-end"' : '') . '>' . (isset($c[1]) ? e($c[1]($v)) : e($v)) . '</td>';
        }
        echo '</tr>';
    }
    if (count($t['rows']) > 1 && $totals) {
        echo '<tr class="total-row">';
        $i = 0;
        foreach ($t['cols'] as $k => $c) {
            if (!$i++) { echo '<td>Total</td>'; continue; }
            $v = $totals[$k] ?? null;
            if ($k === 'conversion') $v = pct($totals['converted'] ?? 0, $totals['leads'] ?? 0);
            if ($k === 'completion') $v = pct($totals['completed'] ?? 0, $totals['jobs'] ?? 0);
            echo '<td class="text-end">' . ($v === null ? '' : e(isset($c[1]) ? $c[1]($v) : $v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
};
?>
<div class="report-bar panel mb-4">
  <div class="status-tabs mb-0">
    <?php foreach ($tabs as $k => [$label, $icon]): ?>
      <a class="stab<?= $tab === $k ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/reports', $q, ['tab' => $k])) ?>"><i class="bi <?= $icon ?>"></i> <?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="d-flex flex-wrap gap-2 align-items-center" method="get" action="<?= e(url('admin/reports')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input class="form-control form-control-sm" type="date" name="from" value="<?= e($r['from']) ?>" aria-label="From" style="max-width:150px">
    <span class="text-muted-2">to</span>
    <input class="form-control form-control-sm" type="date" name="to" value="<?= e($r['to']) ?>" aria-label="To" style="max-width:150px">
    <button class="btn btn-grad btn-sm" type="submit">Apply</button>
  </form>
  <div class="d-flex flex-wrap gap-1">
    <?php foreach ($presets as $label => [$f, $t]): ?>
      <a class="chip-link<?= $r['from'] === $f && $r['to'] === $t ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/reports', $q, ['from' => $f, 'to' => $t])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($tab === 'overview'): ?>
  <section class="kpi-grid mb-4">
    <div class="kpi"><span class="kpi-icon"><i class="bi bi-person-lines-fill"></i></span><div class="kpi-body"><span class="kpi-label">Leads</span><strong class="kpi-value"><?= $int($o['leads']) ?></strong><span class="kpi-sub"><?= $int($o['callbacks']) ?> callbacks · <?= $int($o['repeat_leads']) ?> repeat</span></div></div>
    <div class="kpi kpi-accent"><span class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></span><div class="kpi-body"><span class="kpi-label">Conversion</span><strong class="kpi-value"><?= $percent($o['conversion']) ?></strong><span class="kpi-sub"><?= $int($o['converted']) ?> converted · <?= $int($o['lost']) ?> lost</span></div></div>
    <div class="kpi"><span class="kpi-icon"><i class="bi bi-calendar2-check"></i></span><div class="kpi-body"><span class="kpi-label">Bookings (by visit date)</span><strong class="kpi-value"><?= $int($o['bookings']) ?></strong><span class="kpi-sub"><?= $percent($o['completion']) ?> completed · <?= $int($o['cancelled']) ?> cancelled</span></div></div>
    <div class="kpi kpi-good"><span class="kpi-icon"><i class="bi bi-check2-circle"></i></span><div class="kpi-body"><span class="kpi-label">Completed jobs</span><strong class="kpi-value"><?= $int($o['completed']) ?></strong><span class="kpi-sub"><?= $int($o['new_customers']) ?> new customers</span></div></div>
    <?php if (can('payments.view')): ?>
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-cash-stack"></i></span><div class="kpi-body"><span class="kpi-label">Collected</span><strong class="kpi-value"><?= e($money($o['collected'])) ?></strong><span class="kpi-sub"><?= e($money($o['refunded'])) ?> refunded</span></div></div>
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-receipt"></i></span><div class="kpi-body"><span class="kpi-label">Average job value</span><strong class="kpi-value"><?= e($money($o['avg_job'])) ?></strong></div></div>
    <?php endif; ?>
  </section>
  <div class="row g-4 mb-2">
    <div class="col-xl-<?= can('payments.view') ? 6 : 12 ?>">
      <section class="panel h-100"><div class="panel-head"><h2>Leads <?= $r['days'] > 62 ? 'per month' : 'per day' ?></h2></div>
        <div class="panel-body"><div class="chart-box"><canvas data-chart="leads" data-type="bar" data-label="Leads" role="img" aria-label="Bar chart of leads over time"></canvas></div></div></section>
    </div>
    <?php if (can('payments.view')): ?>
    <div class="col-xl-6">
      <section class="panel h-100"><div class="panel-head"><h2>Collections <?= $r['days'] > 62 ? 'per month' : 'per day' ?></h2></div>
        <div class="panel-body"><div class="chart-box"><canvas data-chart="revenue" data-type="bar" data-label="Collected" data-money="1" role="img" aria-label="Bar chart of payments collected over time"></canvas></div></div></section>
    </div>
    <?php endif; ?>
  </div>
  <script type="application/json" id="chartData"><?= js_json([
      'leads'   => array_map(fn($x) => ['label' => $x['label'], 'value' => $x['leads']], $leadTl),
      'revenue' => isset($revTl) ? array_map(fn($x) => ['label' => $x['label'], 'value' => $x['collected']], $revTl) : [],
  ]) ?></script>
  <div class="mt-4"></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
  <details class="panel mb-4 details-panel"><summary>Tables &amp; export</summary><div class="p-3">
    <?php foreach ($tables as $k => $t) $renderTable($k, $t); ?>
  </div></details>
<?php else: ?>
  <?php foreach ($tables as $k => $t) $renderTable($k, $t); ?>
<?php endif; ?>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
