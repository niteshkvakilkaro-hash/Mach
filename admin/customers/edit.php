<?php
/** Create (no ?number) or edit a customer. Phone numbers are unique across customers. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$number = clean_str($_GET['number'] ?? '', 20);
$c = $number !== '' ? customer_find($number) : null;
$me = require_permission($c ? 'customers.edit' : 'customers.create');
if ($number !== '' && !$c) {
    flash_set('error', 'Customer not found.');
    redirect(url('admin/customers'));
}

$values = $c ?: ['name' => '', 'phone' => '', 'alt_phone' => '', 'email' => '', 'address' => '', 'city' => get_locations()[0]['name'] ?? '', 'pincode' => '', 'location_id' => '', 'notes' => ''];
$errors = [];
$existing = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $errors['_'] = 'Your session expired. Please submit again.';
    [$data, $fieldErrors] = customer_validate($_POST);
    $errors += $fieldErrors;
    $values = array_merge($values, array_map(fn($v) => $v ?? '', $data));
    if (!$errors) {
        $existing = db_one('SELECT customer_number, name, deleted_at FROM customers WHERE phone = ? AND id <> ?', [$data['phone'], $c['id'] ?? 0]);
        if ($existing) {
            $errors['phone'] = 'This number belongs to another customer.';
        }
    }
    if (!$errors) {
        if ($c) {
            $old = array_intersect_key($c, $data);
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
            db_query("UPDATE customers SET $sets WHERE id = ?", array_merge(array_values($data), [$c['id']]));
            audit_log((int) $me['id'], 'updated', 'customers', (int) $c['id'], "Edited customer {$c['customer_number']}", $old, $data);
            flash_set('success', 'Customer updated.');
            redirect(url('admin/customers/view?number=' . $c['customer_number']));
        }
        $num = next_number('CUS');
        $id = db_insert('customers', $data + ['customer_number' => $num, 'source' => 'phone']);
        audit_log((int) $me['id'], 'created', 'customers', $id, "Created customer $num");
        db_query('UPDATE leads SET customer_id = ? WHERE phone = ? AND customer_id IS NULL', [$id, $data['phone']]);
        flash_set('success', "Customer $num created.");
        redirect(url('admin/customers/view?number=' . $num));
    }
}

$fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
$admin = ['title' => $c ? 'Edit ' . $c['name'] : 'New customer', 'subtitle' => $c['customer_number'] ?? 'Customers are also created automatically when a lead is booked', 'active' => 'customers'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>
<?php if ($existing && !$existing['deleted_at']): ?>
  <div class="alert alert-warning">This phone number already belongs to <a href="<?= e(url('admin/customers/view?number=' . $existing['customer_number'])) ?>"><?= e($existing['name']) ?> (<?= e($existing['customer_number']) ?>)</a>.</div>
<?php elseif ($existing): ?>
  <div class="alert alert-warning">This phone number belongs to a deleted customer (<?= e($existing['customer_number']) ?>). It will be restored automatically when you book for this number.</div>
<?php endif; ?>
<form method="post" class="panel" style="max-width:860px" novalidate>
  <?= csrf_field() ?>
  <div class="panel-body row g-3">
    <div class="col-md-6"><label class="form-label" for="cName">Name *</label><input class="form-control<?= $fc('name') ?>" id="cName" name="name" value="<?= e($values['name']) ?>" maxlength="100" required><?= $fm('name') ?></div>
    <div class="col-md-6"><label class="form-label" for="cPhone">Mobile *</label><input class="form-control<?= $fc('phone') ?>" id="cPhone" name="phone" value="<?= e($values['phone']) ?>" inputmode="tel" required><?= $fm('phone') ?></div>
    <div class="col-md-6"><label class="form-label" for="cAlt">Alternate mobile</label><input class="form-control<?= $fc('alt_phone') ?>" id="cAlt" name="alt_phone" value="<?= e($values['alt_phone']) ?>" inputmode="tel"><?= $fm('alt_phone') ?></div>
    <div class="col-md-6"><label class="form-label" for="cEmail">Email</label><input class="form-control<?= $fc('email') ?>" id="cEmail" name="email" type="email" value="<?= e($values['email']) ?>" maxlength="150"><?= $fm('email') ?></div>
    <div class="col-12"><label class="form-label" for="cAddr">Address</label><input class="form-control" id="cAddr" name="address" value="<?= e($values['address']) ?>" maxlength="500"></div>
    <div class="col-md-4"><label class="form-label" for="cArea">Area</label>
      <select class="form-select" id="cArea" name="location_id"><option value="">—</option>
        <?php foreach (get_locations() as $city): ?><optgroup label="<?= e($city['name']) ?>"><?php foreach ($city['areas'] as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (string) $values['location_id'] === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label" for="cCity">City</label><input class="form-control" id="cCity" name="city" value="<?= e($values['city']) ?>" maxlength="80"></div>
    <div class="col-md-4"><label class="form-label" for="cPin">Pincode</label><input class="form-control<?= $fc('pincode') ?>" id="cPin" name="pincode" value="<?= e($values['pincode']) ?>" maxlength="6" inputmode="numeric"><?= $fm('pincode') ?></div>
    <div class="col-12"><label class="form-label" for="cNotes">Internal notes</label><textarea class="form-control" id="cNotes" name="notes" rows="3" maxlength="2000"><?= e($values['notes']) ?></textarea></div>
  </div>
  <div class="panel-foot">
    <a class="btn btn-ghost" href="<?= e($c ? url('admin/customers/view?number=' . $c['customer_number']) : url('admin/customers')) ?>">Cancel</a>
    <button class="btn btn-grad" type="submit"><?= $c ? 'Save changes' : 'Create customer' ?></button>
  </div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
