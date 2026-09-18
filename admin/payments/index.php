<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('payments.view');

$f = payment_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$result = payment_search($f, $page, $perPage);
$totals = payment_totals($f);
$outstanding = payments_outstanding();
$rangeLabel = in_array($f['from'], ['', '2000-01-01'], true) ? 'All time' : ($f['from'] ? date('d M', strtotime($f['from'])) : 'Start') . ' – ' . ($f['to'] ? date('d M Y', strtotime($f['to'])) : 'today');
$presets = [
    'Today'      => [date('Y-m-d'), date('Y-m-d')],
    'This week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];

$admin = [
    'title'    => 'Payments',
    'subtitle' => $rangeLabel,
    'active'   => 'payments',
    'actions'  => '<a class="btn btn-ghost btn-sm" href="' . e(admin_query_url('admin/payments/export', $f)) . '"><i class="bi bi-download"></i> Export CSV</a>',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<section class="kpi-grid mb-4" aria-label="Payment totals">
  <div class="kpi kpi-good"><span class="kpi-icon"><i class="bi bi-cash-stack"></i></span><div class="kpi-body"><span class="kpi-label">Collected</span><strong class="kpi-value"><?= e(format_inr($totals['collected'])) ?></strong><span class="kpi-sub"><?= (int) $totals['paid_count'] ?> payments</span></div></div>
  <div class="kpi"><span class="kpi-icon"><i class="bi bi-hourglass-split"></i></span><div class="kpi-body"><span class="kpi-label">Pending (cheque etc.)</span><strong class="kpi-value"><?= e(format_inr($totals['pending'])) ?></strong></div></div>
  <div class="kpi"><span class="kpi-icon"><i class="bi bi-arrow-counterclockwise"></i></span><div class="kpi-body"><span class="kpi-label">Refunded</span><strong class="kpi-value"><?= e(format_inr($totals['refunded'])) ?></strong></div></div>
  <a class="kpi<?= $outstanding['jobs'] ? ' kpi-danger' : '' ?>" href="<?= e(url('admin/bookings?view=completed')) ?>"><span class="kpi-icon"><i class="bi bi-exclamation-circle"></i></span><div class="kpi-body"><span class="kpi-label">Still to collect</span><strong class="kpi-value"><?= e(format_inr($outstanding['amount'])) ?></strong><span class="kpi-sub"><?= (int) $outstanding['jobs'] ?> finished job<?= (int) $outstanding['jobs'] === 1 ? '' : 's' ?> unpaid (all time)</span></div></a>
</section>

<?php if ($totals['by_method']): ?>
<section class="panel mb-4">
  <div class="panel-head"><h2>By payment method</h2><span class="small text-muted-2"><?= e($rangeLabel) ?></span></div>
  <div class="method-bars">
    <?php $max = max(array_column($totals['by_method'], 'total')); foreach ($totals['by_method'] as $m): ?>
      <div class="method-row">
        <span class="method-name"><?= e(PAYMENT_METHODS[$m['payment_method']] ?? $m['payment_method']) ?></span>
        <span class="method-track" role="img" aria-label="<?= e(PAYMENT_METHODS[$m['payment_method']] ?? '') ?> <?= e(format_inr($m['total'])) ?>"><i style="width:<?= $max > 0 ? round($m['total'] / $max * 100, 1) : 0 ?>%"></i></span>
        <span class="method-val"><?= e(format_inr($m['total'])) ?> <small class="text-muted-2">· <?= (int) $m['n'] ?></small></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/payments')) ?>">
  <div class="filter-row">
    <div class="filter-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Payment / booking no., customer, phone or reference" aria-label="Search payments"></div>
    <select class="form-select" name="status" style="max-width:150px" aria-label="Status"><?= select_options(PAYMENT_STATUSES, $f['status'], 'Any status') ?></select>
    <select class="form-select" name="method" style="max-width:160px" aria-label="Method"><?= select_options(PAYMENT_METHODS, $f['method'], 'Any method') ?></select>
    <input class="form-control" type="date" name="from" value="<?= e($f['from']) ?>" style="max-width:160px" aria-label="From date">
    <input class="form-control" type="date" name="to" value="<?= e($f['to']) ?>" style="max-width:160px" aria-label="To date">
    <button class="btn btn-grad" type="submit">Apply</button>
  </div>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <?php foreach ($presets as $label => [$from, $to]): ?>
      <a class="stab<?= $f['from'] === $from && $f['to'] === $to ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/payments', $f, ['from' => $from, 'to' => $to, 'page' => null])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a class="stab<?= $f['from'] === '2000-01-01' ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/payments', $f, ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'page' => null])) ?>">All time</a>
  </div>
</form>

<section class="panel">
  <?php if ($result['rows']): ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover mb-0 leads-table">
        <thead><tr><th>Payment</th><th>Customer</th><th>Booking</th><th>Method</th><th>Collected by</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($result['rows'] as $p): ?>
            <tr>
              <td><span class="mono fw-semibold"><?= e($p['payment_number']) ?></span><small class="d-block text-muted-2"><?= e(admin_datetime($p['payment_date'] ?? $p['created_at'])) ?></small></td>
              <td><strong><?= e($p['customer_name']) ?></strong><small class="d-block text-muted-2"><?= e($p['phone']) ?></small></td>
              <td><a class="mono" href="<?= e(url('admin/bookings/view?number=' . $p['booking_number'])) ?>"><?= e($p['booking_number']) ?></a></td>
              <td><?= e(PAYMENT_METHODS[$p['payment_method']]) ?><?php if ($p['transaction_id']): ?><small class="d-block text-muted-2 text-truncate" style="max-width:160px"><?= e($p['transaction_id']) ?></small><?php endif; ?></td>
              <td><?= $p['technician_name'] ? '<i class="bi bi-person-gear"></i> ' . e($p['technician_name']) : e($p['user_name'] ?? '—') ?></td>
              <td><?= status_badge(PAYMENT_STATUSES[$p['status']], PAYMENT_STATUS_COLORS[$p['status']]) ?></td>
              <td class="text-end fw-semibold text-nowrap"><?= e(format_inr($p['amount'], true)) ?></td>
              <td class="text-end"><a class="icon-btn icon-btn-sm" href="<?= e(url('admin/payments/receipt?number=' . $p['payment_number'])) ?>" target="_blank" title="Receipt" aria-label="Receipt"><i class="bi bi-receipt"></i></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot">
      <span class="text-muted-2 small">Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $result['total'])) ?> of <?= number_format($result['total']) ?></span>
      <?= admin_pagination('admin/payments', $f, $page, $result['total'], $perPage) ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><i class="bi bi-wallet2"></i><h2 class="h4">No payments in this period</h2><p>Payments are recorded from a booking page or by technicians in their app.</p></div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
