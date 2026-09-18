<?php
/**
 * Schedule a booking — from a lead (?lead=LEAD-…) or for an existing customer (?customer=CUS-…).
 * Shows each technician's load for the chosen date so staff can pick someone free.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('bookings.create');

$lead = !empty($_GET['lead']) ? lead_find(clean_str($_GET['lead'], 20)) : null;
$customer = !$lead && !empty($_GET['customer']) ? customer_find(clean_str($_GET['customer'], 20)) : null;
if (!$lead && !$customer) {
    flash_set('error', 'Open a lead or a customer and choose “Schedule booking”.');
    redirect(url('admin/bookings'));
}

$source = $lead ?: $customer;
$values = [
    'service_id'       => $lead['service_id'] ?? '',
    'scheduled_date'   => $lead && $lead['preferred_date'] >= date('Y-m-d') ? $lead['preferred_date'] : date('Y-m-d', strtotime('+1 day')),
    'scheduled_time'   => $lead['preferred_time'] ?? '',
    'address'          => $source['address'] ?? '',
    'city'             => $source['city'] ?? (get_locations()[0]['name'] ?? ''),
    'pincode'          => $source['pincode'] ?? '',
    'location_id'      => $source['location_id'] ?? '',
    'technician_id'    => $lead['technician_id'] ?? '',
    'estimated_amount' => '',
    'notes'            => $lead ? trim(($lead['problem_type'] ?? '') . ($lead['description'] ? "\n" . $lead['description'] : '')) : '',
];
if ($values['service_id'] !== '' && $values['service_id'] !== null) {
    foreach (get_services() as $s) if ((int) $s['id'] === (int) $values['service_id']) $values['estimated_amount'] = rtrim(rtrim((string) $s['starting_price'], '0'), '.');
}
$openBookings = $lead ? db_all(
    "SELECT b.booking_number, b.scheduled_date, b.scheduled_time, bs.label, bs.color FROM bookings b JOIN booking_statuses bs ON bs.slug = b.status
     WHERE b.lead_id = ? AND b.deleted_at IS NULL AND bs.is_closed = 0", [$lead['id']]) : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $errors['_'] = 'Your session expired. Please submit again.';
    [$data, $fieldErrors] = booking_validate($_POST);
    $errors += $fieldErrors;
    $values = array_merge($values, array_map(fn($v) => $v ?? '', $data));
    if (!$errors) {
        $cust = $lead
            ? ['name' => $lead['customer_name'], 'phone' => $lead['phone'], 'email' => $lead['email'], 'address' => $data['address'],
               'city' => $data['city'], 'pincode' => $data['pincode'], 'location_id' => $data['location_id'], 'source' => $lead['source']]
            : ['name' => $customer['name'], 'phone' => $customer['phone']];
        $created = booking_create($lead, $cust, $data, (int) $me['id']);
        flash_set('success', "Booking {$created['booking_number']} created.");
        redirect(url('admin/bookings/view?number=' . $created['booking_number']));
    }
}

$load = technician_day_load($values['scheduled_date'] ?: date('Y-m-d'));
$slots = time_slots();
if ($values['scheduled_time'] !== '' && !in_array($values['scheduled_time'], $slots, true)) $slots[] = $values['scheduled_time'];
$fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';

$admin = [
    'title'    => 'Schedule booking',
    'subtitle' => $lead ? "From lead {$lead['lead_number']} · " . ($lead['customer_name'] ?: $lead['phone']) : "For {$customer['name']} ({$customer['customer_number']})",
    'active'   => 'bookings',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>
<?php if ($openBookings): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> This lead already has an open booking:
    <?php foreach ($openBookings as $ob): ?><a class="mono ms-1" href="<?= e(url('admin/bookings/view?number=' . $ob['booking_number'])) ?>"><?= e($ob['booking_number']) ?></a> (<?= e(date('d M', strtotime($ob['scheduled_date']))) ?>, <?= e($ob['label']) ?>)<?php endforeach; ?>.
    Create another only if it is a separate visit.
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-8">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Visit</h2></div>
        <div class="panel-body row g-3">
          <div class="col-md-6"><label class="form-label" for="bSvc">Service *</label>
            <select class="form-select<?= $fc('service_id') ?>" id="bSvc" name="service_id" required data-price-target="#bEst"><option value="">Select service</option>
              <?php foreach (get_categories_with_services() as $c): ?><optgroup label="<?= e($c['name']) ?>">
                <?php foreach ($c['services'] as $s): ?><option value="<?= (int) $s['id'] ?>" data-price="<?= e(rtrim(rtrim((string) $s['starting_price'], '0'), '.')) ?>"<?= (string) $values['service_id'] === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
              </optgroup><?php endforeach; ?>
            </select><?= $fm('service_id') ?></div>
          <div class="col-md-6"><label class="form-label" for="bEst">Estimated amount (₹)</label>
            <input class="form-control<?= $fc('estimated_amount') ?>" id="bEst" name="estimated_amount" inputmode="decimal" value="<?= e($values['estimated_amount']) ?>"><?= $fm('estimated_amount') ?></div>
          <div class="col-md-6"><label class="form-label" for="bDate">Date *</label>
            <input class="form-control<?= $fc('scheduled_date') ?>" id="bDate" name="scheduled_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($values['scheduled_date']) ?>" required data-load-date><?= $fm('scheduled_date') ?></div>
          <div class="col-md-6"><label class="form-label" for="bTime">Time slot *</label>
            <select class="form-select<?= $fc('scheduled_time') ?>" id="bTime" name="scheduled_time" required><?= select_options(array_combine($slots, $slots), $values['scheduled_time'], 'Select slot') ?></select><?= $fm('scheduled_time') ?></div>
          <div class="col-12"><label class="form-label" for="bAddr">Visit address *</label>
            <input class="form-control<?= $fc('address') ?>" id="bAddr" name="address" value="<?= e($values['address']) ?>" maxlength="500" required><?= $fm('address') ?></div>
          <div class="col-md-4"><label class="form-label" for="bArea">Area</label>
            <select class="form-select" id="bArea" name="location_id"><option value="">—</option>
              <?php foreach (get_locations() as $c): ?><optgroup label="<?= e($c['name']) ?>"><?php foreach ($c['areas'] as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (string) $values['location_id'] === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label" for="bCity">City</label><input class="form-control" id="bCity" name="city" value="<?= e($values['city']) ?>" maxlength="80"></div>
          <div class="col-md-4"><label class="form-label" for="bPin">Pincode</label><input class="form-control<?= $fc('pincode') ?>" id="bPin" name="pincode" value="<?= e($values['pincode']) ?>" maxlength="6" inputmode="numeric"><?= $fm('pincode') ?></div>
          <div class="col-12"><label class="form-label" for="bNotes">Notes for the technician</label><textarea class="form-control" id="bNotes" name="notes" rows="3" maxlength="2000"><?= e($values['notes']) ?></textarea></div>
        </div>
      </section>
    </div>
    <div class="col-xl-4">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Technician</h2><span class="small text-muted-2" data-load-label>Jobs on <?= e(date('d M', strtotime($values['scheduled_date']))) ?></span></div>
        <div class="panel-body">
          <div class="tech-pick" data-tech-list>
            <label class="tech-option"><input class="form-check-input" type="radio" name="technician_id" value=""<?= !$values['technician_id'] ? ' checked' : '' ?>> <span><strong>Assign later</strong><small>Booking stays “Confirmed”</small></span></label>
            <?php foreach ($load as $t): ?>
              <label class="tech-option">
                <input class="form-check-input" type="radio" name="technician_id" value="<?= $t['id'] ?>"<?= (string) $values['technician_id'] === (string) $t['id'] ? ' checked' : '' ?>>
                <span><strong><?= e($t['name']) ?></strong><small><?= e($t['specialization'] ?: '—') ?><?= $t['status'] === 'on_leave' ? ' · on leave' : '' ?></small></span>
                <span class="load-pill<?= $t['jobs'] >= 4 ? ' is-busy' : '' ?>" data-load-for="<?= $t['id'] ?>" title="<?= e(implode(', ', $t['slots'])) ?>"><?= $t['jobs'] ?> job<?= $t['jobs'] === 1 ? '' : 's' ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?= $fm('technician_id') ?>
        </div>
      </section>
      <section class="panel mb-4">
        <div class="panel-body">
          <dl class="kv-list kv-compact mb-3">
            <div class="kv"><dt>Customer</dt><dd><?= e($source[$lead ? 'customer_name' : 'name'] ?: '—') ?></dd></div>
            <div class="kv"><dt>Phone</dt><dd><?= e($source['phone']) ?></dd></div>
          </dl>
          <button class="btn btn-grad w-100 mb-2" type="submit"><i class="bi bi-calendar-check"></i> Create booking</button>
          <a class="btn btn-ghost w-100" href="<?= e($lead ? lead_url($lead['lead_number']) : url('admin/customers/view?number=' . $customer['customer_number'])) ?>">Cancel</a>
          <p class="form-text mt-3 mb-0">A customer record is created automatically if this phone number doesn't have one.</p>
        </div>
      </section>
    </div>
  </div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
