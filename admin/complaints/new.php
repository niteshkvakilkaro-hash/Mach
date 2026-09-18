<?php
/** Staff logs a complaint received by phone / WhatsApp / in person. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('complaints.manage');

$errors = [];
$v = ['customer_name' => '', 'phone' => '', 'email' => '', 'booking_number' => clean_str($_GET['booking'] ?? '', 20), 'category' => '', 'description' => '', 'priority' => 'normal'];
if ($_GET['booking'] ?? '') {
    $pre = db_one('SELECT c.name, c.phone, c.email FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.booking_number = ?', [$v['booking_number']]);
    if ($pre) $v = array_merge($v, ['customer_name' => $pre['name'], 'phone' => $pre['phone'], 'email' => (string) $pre['email']]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $phone = normalize_phone($v['phone']);
    $name = clean_str($v['customer_name'], 100);
    $bookingNo = strtoupper(clean_str($v['booking_number'], 20));
    if (!csrf_verify()) $errors['_'] = 'Your session expired. Please submit again.';
    if (mb_strlen($name) < 2) $errors['customer_name'] = 'Enter the customer name.';
    if (!$phone) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
    if (!array_key_exists($v['category'], COMPLAINT_CATEGORIES)) $errors['category'] = 'Choose the issue.';
    if ($bookingNo !== '' && !db_value('SELECT 1 FROM bookings WHERE booking_number = ?', [$bookingNo])) $errors['booking_number'] = 'No booking with this number.';
    if (!$errors) {
        $c = complaint_create(['booking_number' => $bookingNo ?: null, 'customer_name' => $name, 'phone' => $phone, 'email' => $v['email'] ?: null,
            'category' => $v['category'], 'description' => clean_str($v['description'], 2000) ?: null, 'source' => 'phone',
            'priority' => in_array($v['priority'], ['normal', 'high', 'urgent'], true) ? $v['priority'] : 'normal'], (int) $me['id']);
        complaint_assign(complaint_find($c['complaint_number']), (int) $me['id'], (int) $me['id']);
        flash_set('success', "Complaint {$c['complaint_number']} logged and assigned to you.");
        redirect(url('admin/complaints/view?number=' . $c['complaint_number']));
    }
}
$fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$admin = ['title' => 'Log a complaint', 'subtitle' => 'For complaints received by phone, WhatsApp or in person', 'active' => 'complaints'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" class="panel" style="max-width:860px" novalidate>
  <?= csrf_field() ?>
  <div class="panel-body row g-3">
    <div class="col-md-6"><label class="form-label" for="nName">Customer name *</label><input class="form-control<?= $fc('customer_name') ?>" id="nName" name="customer_name" value="<?= e($v['customer_name']) ?>" maxlength="100"><?= $fm('customer_name') ?></div>
    <div class="col-md-6"><label class="form-label" for="nPhone">Mobile *</label><input class="form-control<?= $fc('phone') ?>" id="nPhone" name="phone" value="<?= e($v['phone']) ?>" inputmode="tel"><?= $fm('phone') ?></div>
    <div class="col-md-6"><label class="form-label" for="nBk">Booking number</label><input class="form-control<?= $fc('booking_number') ?>" id="nBk" name="booking_number" value="<?= e($v['booking_number']) ?>" placeholder="Leave empty to use the latest completed job"><?= $fm('booking_number') ?></div>
    <div class="col-md-6"><label class="form-label" for="nEmail">Email</label><input class="form-control<?= $fc('email') ?>" id="nEmail" name="email" type="email" value="<?= e($v['email']) ?>" maxlength="150"><?= $fm('email') ?></div>
    <div class="col-md-8"><label class="form-label" for="nCat">Issue *</label><select class="form-select<?= $fc('category') ?>" id="nCat" name="category"><?= select_options(COMPLAINT_CATEGORIES, $v['category'], 'Choose…') ?></select><?= $fm('category') ?></div>
    <div class="col-md-4"><label class="form-label" for="nPrio">Priority</label><select class="form-select" id="nPrio" name="priority"><?= select_options(['normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'], $v['priority']) ?></select></div>
    <div class="col-12"><label class="form-label" for="nDesc">Details</label><textarea class="form-control" id="nDesc" name="description" rows="4" maxlength="2000"><?= e($v['description']) ?></textarea></div>
  </div>
  <div class="panel-foot"><a class="btn btn-ghost" href="<?= e(url('admin/complaints')) ?>">Cancel</a><button class="btn btn-grad" type="submit">Log complaint</button></div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
