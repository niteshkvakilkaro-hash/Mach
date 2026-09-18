<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('technicians.view');

$t = technician_find((int) ($_GET['id'] ?? 0));
if (!$t) {
    http_response_code(404);
    $admin = ['title' => 'Technician not found', 'active' => 'technicians'];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2>Technician not found</h2><a class="btn btn-grad" href="' . e(url('admin/technicians')) . '">Back</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}
$stats = technician_stats((int) $t['id']);
$showMoney = can('payments.view');
$upcoming = tech_jobs((int) $t['id'], 'today');
$upcoming = array_merge($upcoming, tech_jobs((int) $t['id'], 'upcoming'));
$recent = tech_jobs((int) $t['id'], 'done');
$serviceNames = array_column(array_filter(get_services(), fn($s) => in_array((int) $s['id'], $t['service_ids'], true)), 'name');
$areaNames = [];
foreach (get_locations() as $c) foreach ($c['areas'] as $a) if (in_array((int) $a['id'], $t['location_ids'], true)) $areaNames[] = $a['name'];
$statusColor = ['active' => 'success', 'on_leave' => 'warning', 'inactive' => 'secondary'][$t['status']];

$actions = '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $t['phone'])) . '"><i class="bi bi-telephone"></i> Call</a>'
    . '<a class="btn btn-wa btn-sm" href="' . e(whatsapp_link('', '91' . $t['phone'])) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
if (can('technicians.edit')) $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/technicians/edit?id=' . $t['id'])) . '"><i class="bi bi-pencil"></i> Edit</a>';
if (can('technicians.delete')) $actions .= '<button class="btn btn-ghost btn-sm text-danger" type="button" data-confirm-post="api/technicians/delete.php" data-number="' . (int) $t['id'] . '" data-confirm-title="Remove technician?" data-confirm-text="' . e($t['name'] . ' will be removed and their app login disabled. Past bookings keep their name.') . '" data-after="' . e(url('admin/technicians')) . '" aria-label="Remove technician"><i class="bi bi-trash"></i></button>';

$admin = ['title' => $t['name'], 'subtitle' => $t['specialization'] ?: 'Technician', 'active' => 'technicians', 'actions' => $actions];
require ROOT_PATH . '/includes/admin/header.php';
$jobRow = function (array $j) {
    $link = url('admin/bookings/view?number=' . $j['booking_number']);
    echo '<li><div class="flex-grow-1 min-w-0"><a class="mono" href="' . e($link) . '">' . e($j['booking_number']) . '</a> · <strong>' . e($j['customer_name']) . '</strong>'
        . '<small class="d-block text-muted-2 text-truncate">' . e($j['service_name'] ?? '—') . ' · ' . e($j['area_name'] ?? $j['city'] ?? '') . '</small></div>'
        . '<small class="text-end text-muted-2 text-nowrap">' . e(date('d M', strtotime($j['scheduled_date']))) . '<br>' . e($j['scheduled_time']) . '</small>'
        . status_badge($j['status_label'], $j['status_color']) . '</li>';
};
?>
<div class="row g-4">
  <div class="col-xl-4">
    <section class="panel mb-4">
      <div class="panel-body text-center">
        <?php if ($t['profile_photo']): ?><img class="tech-photo tech-photo-xl mb-3" src="<?= e(url($t['profile_photo'])) ?>" alt="<?= e($t['name']) ?>"><?php else: ?><span class="avatar tech-photo tech-photo-xl mb-3"><?= e(initials($t['name'])) ?></span><?php endif; ?>
        <h2 class="h5 mb-1"><?= e($t['name']) ?></h2>
        <div class="mb-2"><?= status_badge(TECHNICIAN_STATUSES[$t['status']], $statusColor) ?></div>
        <small class="text-muted-2"><?= $t['experience_years'] !== null ? (int) $t['experience_years'] . ' years experience · ' : '' ?>Joined <?= e($t['joining_date'] ? date('M Y', strtotime($t['joining_date'])) : '—') ?></small>
      </div>
      <dl class="panel-body kv-list kv-compact mb-0 border-top-line">
        <div class="kv"><dt>Phone</dt><dd><a href="<?= e(tel_link('+91' . $t['phone'])) ?>"><?= e($t['phone']) ?></a></dd></div>
        <div class="kv"><dt>Email</dt><dd><?= e($t['email'] ?: '—') ?></dd></div>
        <div class="kv"><dt>Address</dt><dd><?= e($t['address'] ?: '—') ?></dd></div>
        <div class="kv"><dt>Commission</dt><dd><?= e(rtrim(rtrim((string) $t['commission_percent'], '0'), '.') ?: '0') ?>%</dd></div>
        <div class="kv"><dt>App login</dt><dd><?= $t['user_id'] && $t['login_status'] === 'active' ? e($t['login_email']) . '<small class="d-block text-muted-2">Last sign-in: ' . e($t['last_login_at'] ? admin_datetime($t['last_login_at']) : 'never') . '</small>' : '<span class="text-muted-2">Not enabled</span>' ?></dd></div>
      </dl>
    </section>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Services</h2></div>
      <div class="panel-body"><?php if ($serviceNames): foreach ($serviceNames as $n): ?><span class="tag me-1 mb-1"><?= e($n) ?></span><?php endforeach; else: ?><span class="text-muted-2 small">None selected</span><?php endif; ?></div>
      <div class="panel-head border-top-line"><h2>Areas</h2></div>
      <div class="panel-body"><?php foreach ($areaNames as $n): ?><span class="tag me-1 mb-1"><?= e($n) ?></span><?php endforeach; ?><?php if ($t['service_areas']): ?><div class="small text-muted-2 mt-1"><?= e($t['service_areas']) ?></div><?php elseif (!$areaNames): ?><span class="text-muted-2 small">None selected</span><?php endif; ?></div>
    </section>
  </div>

  <div class="col-xl-8">
    <section class="kpi-grid mb-4" aria-label="Job numbers">
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-calendar-day"></i></span><div class="kpi-body"><span class="kpi-label">Today's jobs</span><strong class="kpi-value"><?= (int) $stats['today'] ?></strong></div></div>
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-hourglass-split"></i></span><div class="kpi-body"><span class="kpi-label">Pending jobs</span><strong class="kpi-value"><?= (int) $stats['pending'] ?></strong></div></div>
      <div class="kpi kpi-good"><span class="kpi-icon"><i class="bi bi-check2-circle"></i></span><div class="kpi-body"><span class="kpi-label">Completed</span><strong class="kpi-value"><?= (int) $stats['completed'] ?></strong></div></div>
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-x-circle"></i></span><div class="kpi-body"><span class="kpi-label">Cancelled</span><strong class="kpi-value"><?= (int) $stats['cancelled'] ?></strong></div></div>
      <div class="kpi"><span class="kpi-icon"><i class="bi bi-collection"></i></span><div class="kpi-body"><span class="kpi-label">Total jobs</span><strong class="kpi-value"><?= (int) $stats['total'] ?></strong></div></div>
      <?php if ($showMoney): ?>
        <div class="kpi"><span class="kpi-icon"><i class="bi bi-currency-rupee"></i></span><div class="kpi-body"><span class="kpi-label">Job value (all time)</span><strong class="kpi-value"><?= e(format_inr($stats['job_value'])) ?></strong><span class="kpi-sub"><?= e(format_inr($stats['month_value'])) ?> this month</span></div></div>
        <div class="kpi"><span class="kpi-icon"><i class="bi bi-wallet2"></i></span><div class="kpi-body"><span class="kpi-label">Cash collected</span><strong class="kpi-value"><?= e(format_inr($stats['collected'])) ?></strong></div></div>
      <?php endif; ?>
    </section>

    <section class="panel mb-4">
      <div class="panel-head"><h2>Today &amp; upcoming</h2></div>
      <?php if ($upcoming): ?><ul class="list-rows"><?php foreach ($upcoming as $j) $jobRow($j); ?></ul><?php else: ?><div class="empty-mini">No open jobs.</div><?php endif; ?>
    </section>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Recently closed</h2><span class="small text-muted-2">Last 30 days</span></div>
      <?php if ($recent): ?><ul class="list-rows"><?php foreach (array_slice($recent, 0, 10) as $j) $jobRow($j); ?></ul><?php else: ?><div class="empty-mini">Nothing in the last 30 days.</div><?php endif; ?>
    </section>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
