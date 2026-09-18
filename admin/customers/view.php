<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('customers.view');

$c = customer_find(clean_str($_GET['number'] ?? '', 20));
if (!$c) {
    http_response_code(404);
    $admin = ['title' => 'Customer not found', 'active' => 'customers'];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2>Customer not found</h2><a class="btn btn-grad" href="' . e(url('admin/customers')) . '">Back to customers</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}

$bookings = db_all(
    "SELECT b.booking_number, b.scheduled_date, b.scheduled_time, b.final_amount, b.estimated_amount, b.payment_status, b.warranty_until,
            s.name AS service_name, t.name AS technician_name, bs.label, bs.color
     FROM bookings b JOIN booking_statuses bs ON bs.slug = b.status LEFT JOIN services s ON s.id = b.service_id LEFT JOIN technicians t ON t.id = b.technician_id
     WHERE b.customer_id = ? AND b.deleted_at IS NULL ORDER BY b.scheduled_date DESC, b.id DESC", [$c['id']]);
[$vis, $vp] = lead_visibility('l');
$leads = can('leads.view') ? db_all(
    "SELECT l.lead_number, l.created_at, s.name AS service_name, st.label, st.color FROM leads l JOIN lead_statuses st ON st.slug = l.status
     LEFT JOIN services s ON s.id = l.service_id WHERE (l.customer_id = ? OR l.phone = ?) AND l.deleted_at IS NULL $vis ORDER BY l.id DESC",
    array_merge([$c['id'], $c['phone']], $vp)) : [];
$payments = can('payments.view') ? db_all('SELECT p.*, b.booking_number FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.customer_id = ? ORDER BY p.id DESC', [$c['id']]) : [];
$underWarranty = array_filter($bookings, fn($b) => $b['warranty_until'] && $b['warranty_until'] >= date('Y-m-d'));

$actions = '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $c['phone'])) . '"><i class="bi bi-telephone"></i> Call</a>'
    . '<a class="btn btn-wa btn-sm" href="' . e(whatsapp_link('Hi ' . strtok($c['name'], ' ') . ', this is ' . setting('business_name') . '.', '91' . $c['phone'])) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
if (can('customers.edit')) $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/customers/edit?number=' . $c['customer_number'])) . '"><i class="bi bi-pencil"></i> Edit</a>';
if (can('bookings.create')) $actions .= '<a class="btn btn-grad btn-sm" href="' . e(url('admin/bookings/create?customer=' . $c['customer_number'])) . '"><i class="bi bi-calendar-plus"></i> New booking</a>';
if (can('customers.delete')) $actions .= '<button class="btn btn-ghost btn-sm text-danger" type="button" data-confirm-post="api/customers/delete.php" data-number="' . e($c['customer_number']) . '" data-confirm-title="Delete customer?" data-confirm-text="' . e($c['name'] . ' (' . $c['customer_number'] . ') will be hidden from lists. Bookings and payments are kept.') . '" data-after="' . e(url('admin/customers')) . '" aria-label="Delete customer"><i class="bi bi-trash"></i></button>';

$admin = ['title' => $c['name'], 'subtitle' => $c['customer_number'] . ' · customer since ' . date('M Y', strtotime($c['created_at'])), 'active' => 'customers', 'actions' => $actions];
require ROOT_PATH . '/includes/admin/header.php';
?>
<section class="panel mb-4">
  <div class="month-grid">
    <div><span>Bookings</span><strong><?= (int) $c['total_bookings'] ?></strong></div>
    <?php if (can('payments.view')): ?><div><span>Total spent</span><strong><?= e(format_inr($c['total_spent'])) ?></strong></div><?php endif; ?>
    <div><span>Last service</span><strong><?= $c['last_service_date'] ? e(date('d M Y', strtotime($c['last_service_date']))) : '—' ?></strong></div>
    <div><span>Under warranty</span><strong><?= count($underWarranty) ?></strong></div>
    <div><span>Enquiries</span><strong><?= count($leads) ?></strong></div>
  </div>
</section>

<div class="row g-4">
  <div class="col-xl-4">
    <section class="panel mb-4">
      <div class="panel-head"><h2>Contact</h2></div>
      <dl class="panel-body kv-list kv-compact mb-0">
        <div class="kv"><dt>Phone</dt><dd><a href="<?= e(tel_link('+91' . $c['phone'])) ?>"><?= e($c['phone']) ?></a><?= $c['alt_phone'] ? '<br>' . e($c['alt_phone']) : '' ?></dd></div>
        <div class="kv"><dt>Email</dt><dd><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></dd></div>
        <div class="kv"><dt>Address</dt><dd><?= e($c['address'] ?: '—') ?></dd></div>
        <div class="kv"><dt>Area / City</dt><dd><?= e(trim(($c['area_name'] ? $c['area_name'] . ', ' : '') . ($c['city'] ?? ''), ', ') ?: '—') ?></dd></div>
        <div class="kv"><dt>Pincode</dt><dd><?= e($c['pincode'] ?: '—') ?></dd></div>
        <div class="kv"><dt>Source</dt><dd><?= e(LEAD_SOURCES[$c['source']] ?? ($c['source'] ?: '—')) ?></dd></div>
      </dl>
      <?php if ($c['notes']): ?><div class="panel-body pt-0"><div class="quote-box"><small>Notes</small><?= nl2br(e($c['notes'])) ?></div></div><?php endif; ?>
    </section>
  </div>

  <div class="col-xl-8">
    <section class="panel mb-4">
      <div class="panel-head"><h2>Bookings</h2></div>
      <?php if ($bookings): ?>
        <div class="table-responsive">
          <table class="table admin-table mb-0">
            <thead><tr><th>Booking</th><th>Service</th><th>Visit</th><th>Technician</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($bookings as $b): ?>
                <tr>
                  <td><a class="mono" href="<?= e(url('admin/bookings/view?number=' . $b['booking_number'])) ?>"><?= e($b['booking_number']) ?></a>
                    <?php if ($b['warranty_until'] && $b['warranty_until'] >= date('Y-m-d')): ?><small class="d-block text-success"><i class="bi bi-shield-check"></i> Warranty till <?= e(date('d M', strtotime($b['warranty_until']))) ?></small><?php endif; ?></td>
                  <td><?= e($b['service_name'] ?? '—') ?></td>
                  <td class="text-nowrap"><?= e(date('d M Y', strtotime($b['scheduled_date']))) ?><small class="d-block text-muted-2"><?= e($b['scheduled_time']) ?></small></td>
                  <td><?= e($b['technician_name'] ?? '—') ?></td>
                  <td><?= status_badge($b['label'], $b['color']) ?></td>
                  <td class="text-end"><?= e(format_inr($b['final_amount'] ?? $b['estimated_amount']) ?: '—') ?><small class="d-block text-muted-2"><?= e(ucfirst($b['payment_status'])) ?></small></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?><div class="empty-mini">No bookings yet.</div><?php endif; ?>
    </section>

    <?php $custComplaints = db_all('SELECT complaint_number, category, status, created_at FROM complaints WHERE customer_id = ? OR phone = ? ORDER BY id DESC', [$c['id'], $c['phone']]); if ($custComplaints && can('complaints.view')): ?>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Complaints</h2></div>
      <ul class="list-rows">
        <?php foreach ($custComplaints as $cc): ?>
          <li><div class="flex-grow-1"><a class="mono" href="<?= e(url('admin/complaints/view?number=' . $cc['complaint_number'])) ?>"><?= e($cc['complaint_number']) ?></a><small class="d-block text-muted-2"><?= e(COMPLAINT_CATEGORIES[$cc['category']]) ?> · <?= e(date('d M Y', strtotime($cc['created_at']))) ?></small></div><?= status_badge(COMPLAINT_STATUSES[$cc['status']], COMPLAINT_STATUS_COLORS[$cc['status']]) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($leads): ?>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Enquiries</h2></div>
      <ul class="list-rows">
        <?php foreach ($leads as $l): ?>
          <li><div class="flex-grow-1"><a class="mono" href="<?= e(lead_url($l['lead_number'])) ?>"><?= e($l['lead_number']) ?></a><small class="d-block text-muted-2"><?= e($l['service_name'] ?? '—') ?> · <?= e(date('d M Y', strtotime($l['created_at']))) ?></small></div><?= status_badge($l['label'], $l['color']) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($payments): ?>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Payments</h2></div>
      <ul class="list-rows">
        <?php foreach ($payments as $p): ?>
          <li><span class="row-icon"><i class="bi bi-currency-rupee"></i></span>
            <div class="flex-grow-1"><strong><?= e(format_inr($p['amount'])) ?></strong> · <?= e(strtoupper(str_replace('_', ' ', $p['payment_method']))) ?><small class="d-block text-muted-2 mono"><?= e($p['payment_number']) ?> · <?= e($p['booking_number']) ?></small></div>
            <small class="text-muted-2"><?= e(admin_datetime($p['payment_date'], false)) ?></small><?= status_badge(ucfirst($p['status']), $p['status'] === 'paid' ? 'success' : 'warning') ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
