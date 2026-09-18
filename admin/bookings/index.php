<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('bookings.view');

$f = booking_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$result = booking_search($f, $page, $perPage);
$counts = booking_view_counts($f);
$views = ['today' => 'Today', 'upcoming' => 'Upcoming', 'unassigned' => 'Unassigned', 'open' => 'All open', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'];
$activeFilters = count(array_filter(array_diff_key($f, ['view' => 1, 'q' => 1]), fn($v) => $v !== ''));

$admin = [
    'title'    => 'Bookings',
    'subtitle' => $views[$f['view']] . ' · ' . number_format($result['total']) . ' booking' . ($result['total'] === 1 ? '' : 's'),
    'active'   => 'bookings',
    'actions'  => can('leads.view') ? '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/leads?status=open')) . '"><i class="bi bi-person-lines-fill"></i> Schedule from a lead</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="status-tabs mb-3">
  <?php foreach ($views as $k => $label): ?>
    <a class="stab<?= $f['view'] === $k ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/bookings', $f, ['view' => $k, 'page' => null])) ?>">
      <?php if ($k === 'unassigned' && $counts[$k]): ?><i class="dot sb-warning"></i><?php endif; ?><?= e($label) ?> <b><?= number_format($counts[$k]) ?></b>
    </a>
  <?php endforeach; ?>
</div>

<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/bookings')) ?>">
  <input type="hidden" name="view" value="<?= e($f['view']) ?>">
  <div class="filter-row">
    <div class="filter-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Booking no., customer name or phone" aria-label="Search bookings"></div>
    <button class="btn btn-ghost" type="button" data-bs-toggle="collapse" data-bs-target="#bkFilters" aria-expanded="<?= $activeFilters ? 'true' : 'false' ?>"><i class="bi bi-sliders"></i> Filters<?= $activeFilters ? ' <span class="count-pill">' . $activeFilters . '</span>' : '' ?></button>
    <button class="btn btn-grad" type="submit">Search</button>
    <?php if ($activeFilters || $f['q'] !== ''): ?><a class="btn btn-link btn-sm" href="<?= e(admin_query_url('admin/bookings', ['view' => $f['view']])) ?>">Clear</a><?php endif; ?>
  </div>
  <div class="collapse<?= $activeFilters ? ' show' : '' ?>" id="bkFilters">
    <div class="row g-2 pt-3">
      <div class="col-6 col-md-3"><label class="form-label" for="bfStatus">Status</label><select class="form-select form-select-sm" id="bfStatus" name="status"><?= select_options(array_column(booking_statuses(), 'label', 'slug'), $f['status'], 'Any') ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label" for="bfTech">Technician</label><select class="form-select form-select-sm" id="bfTech" name="technician"><?= select_options(['none' => 'Not assigned'] + array_column(lead_technician_options(), 'name', 'id'), $f['technician'], 'Any') ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label" for="bfSvc">Service</label><select class="form-select form-select-sm" id="bfSvc" name="service"><?= select_options(array_column(get_services(), 'name', 'id'), $f['service'], 'Any') ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label" for="bfFrom">Visit date</label>
        <div class="d-flex gap-1"><input class="form-control form-control-sm" type="date" id="bfFrom" name="from" value="<?= e($f['from']) ?>" aria-label="From"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To"></div></div>
    </div>
  </div>
</form>

<section class="panel">
  <?php if ($result['rows']): ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover mb-0 leads-table">
        <thead><tr><th>Booking</th><th>Customer / area</th><th>Service</th><th>Visit</th><th>Technician</th><th>Status</th><th class="text-end">Amount</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($result['rows'] as $r): $link = url('admin/bookings/view?number=' . $r['booking_number']); $today = $r['scheduled_date'] === date('Y-m-d'); ?>
            <tr>
              <td><a class="mono fw-semibold" href="<?= e($link) ?>"><?= e($r['booking_number']) ?></a><?php if ($r['lead_number']): ?><small class="d-block text-muted-2 mono"><?= e($r['lead_number']) ?></small><?php endif; ?></td>
              <td><a class="text-reset" href="<?= e($link) ?>"><strong><?= e($r['customer_name']) ?></strong></a><small class="d-block text-muted-2"><?= e($r['phone']) ?><?= $r['area_name'] ? ' · ' . e($r['area_name']) : '' ?></small></td>
              <td><?= e($r['service_name'] ?? '—') ?></td>
              <td class="text-nowrap"><?= $today ? '<strong class="text-grad-2">Today</strong>' : e(date('D, d M', strtotime($r['scheduled_date']))) ?><small class="d-block text-muted-2"><?= e($r['scheduled_time']) ?></small></td>
              <td><?= $r['technician_name'] ? e($r['technician_name']) : '<span class="prio prio-high"><i class="bi bi-exclamation-circle"></i> Not assigned</span>' ?></td>
              <td><?= status_badge($r['status_label'], $r['status_color']) ?></td>
              <td class="text-end text-nowrap"><?= e(format_inr($r['final_amount'] ?? $r['quoted_amount'] ?? $r['estimated_amount']) ?: '—') ?><small class="d-block text-muted-2"><?= e(ucfirst($r['payment_status'])) ?></small></td>
              <td class="text-end text-nowrap">
                <a class="icon-btn icon-btn-sm" href="<?= e(tel_link('+91' . $r['phone'])) ?>" title="Call customer" aria-label="Call <?= e($r['customer_name']) ?>"><i class="bi bi-telephone"></i></a>
                <a class="icon-btn icon-btn-sm icon-wa" href="<?= e(whatsapp_link(booking_whatsapp_text($r), '91' . $r['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp <?= e($r['customer_name']) ?>"><i class="bi bi-whatsapp"></i></a>
                <a class="icon-btn icon-btn-sm" href="<?= e($link) ?>" title="Open" aria-label="Open booking"><i class="bi bi-chevron-right"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot">
      <span class="text-muted-2 small">Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $result['total'])) ?> of <?= number_format($result['total']) ?></span>
      <?= admin_pagination('admin/bookings', $f, $page, $result['total'], $perPage) ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <i class="bi bi-calendar2-x"></i>
      <h2 class="h4">No bookings <?= $f['view'] === 'today' ? 'today' : 'here' ?></h2>
      <p>Bookings are created from a lead (“Schedule booking”) or from a customer's page.</p>
    </div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
