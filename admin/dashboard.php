<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/models/dashboard.php';
$me = require_permission('dashboard.view');

$showMoney = can('payments.view');
$leads = dash_lead_kpis();
$follow = dash_followup_kpis();
$book = dash_booking_kpis();
$money = $showMoney ? dash_revenue_kpis() : null;
$customers = can('customers.view') ? dash_customer_count() : null;

$charts = [
    'leadsByDay'     => dash_leads_by_day(30),
    'leadsBySource'  => dash_leads_grouped('source', 30),
    'leadsByService' => dash_leads_grouped('service', 30, 10),
];
if ($showMoney) {
    $charts['revenueByMonth'] = dash_revenue_by_month(6);
}
$latest = dash_latest_leads(6);
$overdue = dash_overdue_followups(6);
$activity = dash_recent_activity(8);
$techs = can('technicians.view') ? dash_technician_performance(8) : [];

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$admin = [
    'title'    => $greeting . ', ' . strtok($me['name'], ' '),
    'subtitle' => 'Here’s what’s happening today, ' . date('l, d M Y'),
    'active'   => 'dashboard',
    'actions'  => '<a class="btn btn-ghost btn-sm" href="' . e(url()) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Website</a>',
];
$adminScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];

/** One KPI card. */
$kpi = function (string $icon, string $label, string $value, string $sub = '', string $tone = '') {
    echo '<div class="kpi' . ($tone ? ' kpi-' . $tone : '') . '"><span class="kpi-icon"><i class="bi ' . e($icon) . '"></i></span>'
        . '<div class="kpi-body"><span class="kpi-label">' . e($label) . '</span><strong class="kpi-value">' . e($value) . '</strong>'
        . ($sub !== '' ? '<span class="kpi-sub">' . $sub . '</span>' : '') . '</div></div>';
};
$num = fn($n) => number_format((int) $n);

/** Server-rendered table view of a chart series (accessibility + exact numbers). */
$tableView = function (array $rows, string $valueLabel, bool $money = false) {
    echo '<details class="chart-table"><summary>View as table</summary><table class="table table-sm mb-0"><thead><tr><th>Label</th><th class="text-end">'
        . e($valueLabel) . '</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($r['label']) . '</td><td class="text-end">' . e($money ? format_inr($r['value']) : number_format($r['value'])) . '</td></tr>';
    }
    echo '</tbody></table></details>';
};

require ROOT_PATH . '/includes/admin/header.php';
?>

<!-- Today -->
<section class="panel today-panel mb-4" aria-labelledby="todayTitle">
  <div class="today-title" id="todayTitle"><i class="bi bi-sun"></i> Today</div>
  <div class="today-grid">
    <div><span>New leads</span><strong><?= $num($leads['today']) ?></strong></div>
    <div><span>Contacted</span><strong><?= $num($leads['today_moves']['contacted'] ?? 0) ?></strong></div>
    <div><span>Scheduled</span><strong><?= $num($leads['today_moves']['scheduled'] ?? 0) ?></strong></div>
    <div><span>Jobs completed</span><strong><?= $num($book['today_completed']) ?></strong></div>
    <?php if ($showMoney): ?><div><span>Revenue</span><strong><?= e(format_inr($money['today'])) ?></strong></div><?php endif; ?>
  </div>
</section>

<!-- KPI cards -->
<section class="kpi-grid mb-4" aria-label="Key numbers">
  <?php
  $kpi('bi-person-lines-fill', 'Total leads', $num($leads['total']));
  $kpi('bi-stars', 'New leads', $num($leads['status_new']), 'Waiting for first contact', $leads['status_new'] ? 'accent' : '');
  $kpi('bi-telephone-outbound', 'Pending follow-ups', $num($follow['due']),
      $follow['overdue'] ? '<i class="bi bi-exclamation-triangle-fill"></i> ' . $num($follow['overdue']) . ' overdue' : 'None overdue',
      $follow['overdue'] ? 'danger' : '');
  $kpi('bi-calendar-event', 'Today’s bookings', $num($book['today']));
  $kpi('bi-person-gear', 'Assigned jobs', $num($book['assigned_open']), 'Open, with technician');
  $kpi('bi-check2-circle', 'Completed jobs', $num($book['completed']), '', 'good');
  $kpi('bi-x-circle', 'Cancelled jobs', $num($book['cancelled']));
  if ($customers !== null) $kpi('bi-people', 'Total customers', $num($customers));
  if (can('complaints.view')) {
      $cc = complaint_open_count();
      $kpi('bi-exclamation-diamond', 'Open complaints', $num($cc['open_total']), $cc['overdue'] ? '<i class="bi bi-alarm"></i> ' . $num($cc['overdue']) . ' past SLA' : 'All within SLA', $cc['overdue'] ? 'danger' : '');
      $fs = feedback_stats(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
      $kpi('bi-star-fill', 'Customer rating (30 days)', $fs['avg_rating'] ? number_format((float) $fs['avg_rating'], 1) . ' ★' : '—', (int) $fs['submitted'] . ' ratings · ' . number_format($fs['happy_pct'], 0) . '% happy', 'good');
  }
  if ($showMoney) $kpi('bi-currency-rupee', 'Total revenue', format_inr($money['total']), format_inr($money['pending']) . ' pending');
  ?>
</section>

<!-- This month -->
<section class="panel mb-4" aria-labelledby="monthTitle">
  <div class="panel-head"><h2 id="monthTitle">This month</h2><span class="text-muted-2 small"><?= e(date('F Y')) ?></span></div>
  <div class="month-grid">
    <div><span>Total leads</span><strong><?= $num($leads['month_total']) ?></strong></div>
    <div><span>Converted</span><strong><?= $num($leads['month_won']) ?></strong></div>
    <div>
      <span>Conversion</span><strong><?= e(number_format($leads['conversion'], 1)) ?>%</strong>
      <div class="meter" role="img" aria-label="Conversion <?= e($leads['conversion']) ?> percent"><i style="width:<?= min(100, (float) $leads['conversion']) ?>%"></i></div>
    </div>
    <div><span>Completed jobs</span><strong><?= $num($book['month_completed']) ?></strong></div>
    <?php if ($showMoney): ?><div><span>Revenue</span><strong><?= e(format_inr($money['month'])) ?></strong></div><?php endif; ?>
  </div>
</section>

<!-- Charts -->
<div class="row g-4 mb-4">
  <div class="col-xl-8">
    <section class="panel h-100">
      <div class="panel-head"><h2>Leads per day</h2><span class="text-muted-2 small">Last 30 days · <?= $num(array_sum(array_column($charts['leadsByDay'], 'value'))) ?> total</span></div>
      <div class="panel-body">
        <div class="chart-box"><canvas data-chart="leadsByDay" data-type="bar" data-label="Leads" role="img" aria-label="Bar chart of leads per day for the last 30 days"></canvas></div>
        <?php $tableView($charts['leadsByDay'], 'Leads'); ?>
      </div>
    </section>
  </div>
  <div class="col-xl-4">
    <section class="panel h-100">
      <div class="panel-head"><h2>Leads by source</h2><span class="text-muted-2 small">Last 30 days</span></div>
      <div class="panel-body">
        <?php if ($charts['leadsBySource']): ?>
          <div class="chart-box chart-box-h" style="--rows:<?= count($charts['leadsBySource']) ?>"><canvas data-chart="leadsBySource" data-type="hbar" data-label="Leads" role="img" aria-label="Bar chart of leads by source"></canvas></div>
          <?php $tableView($charts['leadsBySource'], 'Leads'); ?>
        <?php else: ?><div class="empty-mini">No leads in the last 30 days.</div><?php endif; ?>
      </div>
    </section>
  </div>
  <div class="col-xl-<?= $showMoney ? '6' : '12' ?>">
    <section class="panel h-100">
      <div class="panel-head"><h2>Leads by service</h2><span class="text-muted-2 small">Last 30 days</span></div>
      <div class="panel-body">
        <?php if ($charts['leadsByService']): ?>
          <div class="chart-box chart-box-h" style="--rows:<?= count($charts['leadsByService']) ?>"><canvas data-chart="leadsByService" data-type="hbar" data-label="Leads" role="img" aria-label="Bar chart of leads by service"></canvas></div>
          <?php $tableView($charts['leadsByService'], 'Leads'); ?>
        <?php else: ?><div class="empty-mini">No leads in the last 30 days.</div><?php endif; ?>
      </div>
    </section>
  </div>
  <?php if ($showMoney): ?>
  <div class="col-xl-6">
    <section class="panel h-100">
      <div class="panel-head"><h2>Revenue by month</h2><span class="text-muted-2 small">Paid, last 6 months</span></div>
      <div class="panel-body">
        <div class="chart-box"><canvas data-chart="revenueByMonth" data-type="bar" data-label="Revenue" data-money="1" role="img" aria-label="Bar chart of paid revenue by month"></canvas></div>
        <?php $tableView($charts['revenueByMonth'], 'Revenue', true); ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>

<div class="row g-4 mb-4">
  <!-- Latest leads -->
  <div class="col-xl-7">
    <section class="panel h-100">
      <div class="panel-head"><h2>Latest leads</h2></div>
      <?php if ($latest): ?>
        <div class="table-responsive">
          <table class="table admin-table mb-0">
            <thead><tr><th>Lead</th><th>Customer</th><th>Service</th><th>Status</th><th class="text-end">Received</th></tr></thead>
            <tbody>
              <?php foreach ($latest as $l): ?>
                <tr>
                  <td><span class="mono"><?= e($l['lead_number']) ?></span><?php if ($l['priority'] === 'high' || $l['priority'] === 'urgent'): ?> <span class="badge text-bg-warning" title="Priority"><i class="bi bi-lightning-fill"></i> <?= e(ucfirst($l['priority'])) ?></span><?php endif; ?></td>
                  <td><strong><?= e($l['customer_name'] ?: 'No name') ?></strong><small class="d-block text-muted-2"><?= e($l['phone']) ?><?= $l['city'] ? ' · ' . e($l['city']) : '' ?></small></td>
                  <td><?= e($l['service_name'] ?? '—') ?><small class="d-block text-muted-2"><?= e(LEAD_SOURCES[$l['source']] ?? $l['source']) ?> · <?= e($l['form_type']) ?></small></td>
                  <td><span class="status-badge sb-<?= e($l['status_color']) ?>"><?= e($l['status_label']) ?></span></td>
                  <td class="text-end text-muted-2 small"><?= e(time_ago($l['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty-mini"><i class="bi bi-inbox"></i> No leads yet. Submissions from the website appear here instantly.</div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Overdue follow-ups -->
  <div class="col-xl-5">
    <section class="panel h-100">
      <div class="panel-head"><h2>Due &amp; overdue follow-ups</h2><?php if ($follow['overdue']): ?><span class="status-badge sb-danger"><?= $num($follow['overdue']) ?> overdue</span><?php endif; ?></div>
      <?php if ($overdue): ?>
        <ul class="list-rows">
          <?php foreach ($overdue as $f):
              $late = $f['followup_date'] < date('Y-m-d') || ($f['followup_time'] && $f['followup_date'] === date('Y-m-d') && $f['followup_time'] < date('H:i:s')); ?>
            <li>
              <span class="row-icon<?= $late ? ' is-late' : '' ?>"><i class="bi <?= $f['type'] === 'whatsapp' ? 'bi-whatsapp' : ($f['type'] === 'visit' ? 'bi-geo-alt' : 'bi-telephone') ?>"></i></span>
              <div class="flex-grow-1 min-w-0">
                <strong><?= e($f['customer_name'] ?: $f['phone']) ?></strong> <span class="mono small text-muted-2"><?= e($f['lead_number']) ?></span>
                <small class="d-block text-muted-2 text-truncate"><?= e(ucwords(str_replace('_', ' ', $f['type']))) ?><?= $f['note'] ? ' · ' . e($f['note']) : '' ?></small>
              </div>
              <small class="text-end <?= $late ? 'text-danger' : 'text-muted-2' ?>"><?= e(date('d M', strtotime($f['followup_date']))) ?><br><?= $f['followup_time'] ? e(date('h:i A', strtotime($f['followup_time']))) : '' ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-mini"><i class="bi bi-check2-all"></i> You’re all caught up.</div>
      <?php endif; ?>
    </section>
  </div>
</div>

<div class="row g-4">
  <?php if (can('technicians.view')): ?>
  <div class="col-xl-7">
    <section class="panel h-100">
      <div class="panel-head"><h2>Technician performance</h2><span class="text-muted-2 small">Completed = this month</span></div>
      <?php if ($techs): ?>
        <div class="table-responsive">
          <table class="table admin-table mb-0">
            <thead><tr><th>Technician</th><th class="text-end">Today</th><th class="text-end">Pending</th><th class="text-end">Completed</th><?php if ($showMoney): ?><th class="text-end">Job value</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($techs as $t): ?>
                <tr>
                  <td><span class="avatar avatar-sm"><?= e(initials($t['name'])) ?></span> <?= e($t['name']) ?><?php if ($t['status'] === 'on_leave'): ?> <span class="status-badge sb-warning">On leave</span><?php endif; ?></td>
                  <td class="text-end"><?= $num($t['today']) ?></td>
                  <td class="text-end"><?= $num($t['pending']) ?></td>
                  <td class="text-end"><?= $num($t['completed']) ?></td>
                  <?php if ($showMoney): ?><td class="text-end"><?= e(format_inr($t['revenue'])) ?></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty-mini"><i class="bi bi-person-plus"></i> No technicians added yet.</div>
      <?php endif; ?>
    </section>
  </div>
  <?php endif; ?>

  <div class="col-xl-<?= can('technicians.view') ? '5' : '12' ?>">
    <section class="panel h-100">
      <div class="panel-head"><h2>Recent activity</h2></div>
      <?php if ($activity): ?>
        <ul class="timeline">
          <?php foreach ($activity as $a): ?>
            <li>
              <span class="tl-dot sb-<?= e($a['color']) ?>"></span>
              <div>
                <strong><?= e($a['lead_number']) ?></strong>
                <?= $a['old_status'] === null ? 'created' : 'moved to' ?>
                <span class="status-badge sb-<?= e($a['color']) ?>"><?= e($a['new_label']) ?></span>
                <small class="d-block text-muted-2"><?= e($a['customer_name'] ?: 'No name') ?> · <?= e($a['user_name'] ?? 'Website') ?> · <?= e(time_ago($a['created_at'])) ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-mini"><i class="bi bi-activity"></i> No activity yet.</div>
      <?php endif; ?>
    </section>
  </div>
</div>

<script type="application/json" id="chartData"><?= js_json($charts) ?></script>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
