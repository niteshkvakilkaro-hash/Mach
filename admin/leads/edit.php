<?php
/**
 * Create a lead (phone call / walk-in), edit an existing one (?number=), or create a
 * re-enquiry of a closed lead (?parent=). Warns before creating a duplicate for a phone
 * that already has an open lead: staff can add the enquiry to it, or create a linked re-enquiry.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$number = clean_str($_GET['number'] ?? '', 20);
$lead = $number !== '' ? lead_find($number) : null;
$isEdit = $lead !== null;
$me = require_permission($isEdit ? 'leads.edit' : 'leads.create');
if ($number !== '' && !$lead) {
    flash_set('error', 'Lead not found.');
    redirect(url('admin/leads'));
}

$parent = null;
if (!$isEdit && !empty($_GET['parent'])) {
    $parent = lead_find(clean_str($_GET['parent'], 20));
}

$fields = ['customer_name', 'phone', 'email', 'service_id', 'appliance', 'brand', 'problem_type', 'description', 'address',
           'city', 'pincode', 'location_id', 'preferred_date', 'preferred_time', 'source', 'priority'];
$values = array_fill_keys($fields, '');
$values['source'] = 'phone';
$values['priority'] = 'normal';
$values['city'] = get_locations()[0]['name'] ?? '';
if ($lead) {
    $values = array_intersect_key($lead, $values) + $values;
} elseif ($parent) {
    foreach (['customer_name', 'phone', 'email', 'address', 'city', 'pincode', 'location_id', 'service_id', 'appliance', 'brand'] as $k) {
        $values[$k] = $parent[$k];
    }
}

$errors = [];
$duplicates = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_'] = 'Your session expired. Please submit again.';
    }
    [$data, $fieldErrors] = lead_admin_validate($_POST);
    $errors += $fieldErrors;
    $values = array_merge($values, array_map(fn($v) => $v ?? '', $data));

    if (!$errors) {
        if ($isEdit) {
            lead_admin_update($lead, $data, (int) $me['id']);
            flash_set('success', 'Lead updated.');
            redirect(lead_url($lead['lead_number']));
        }

        $dupAction = $_POST['dup_action'] ?? '';
        $duplicates = $parent ? [] : lead_open_for_phone($data['phone']);
        if ($duplicates && $dupAction === 'merge') {
            $target = lead_find((string) ($_POST['merge_into'] ?? ''));
            if ($target && $target['phone'] === $data['phone']) {
                lead_admin_merge($target, $data, (int) $me['id']);
                flash_set('success', "Enquiry added to existing lead {$target['lead_number']}.");
                redirect(lead_url($target['lead_number']));
            }
            $errors['_'] = 'Choose which open lead to add this enquiry to.';
        } elseif (!$duplicates || $dupAction === 'new') {
            $parentId = $parent ? (int) $parent['id'] : ($duplicates ? (int) $duplicates[0]['id'] : null);
            $created = lead_admin_create($data, (int) $me['id'], $parentId);
            flash_set('success', "Lead {$created['lead_number']} created.");
            redirect(lead_url($created['lead_number']));
        }
        // else: fall through and show the duplicate warning
    }
}

$problems = get_service_problems();
$title = $isEdit ? 'Edit ' . $lead['lead_number'] : ($parent ? 'Re-enquiry of ' . $parent['lead_number'] : 'New lead');
$admin = ['title' => $title, 'subtitle' => $isEdit ? ($lead['customer_name'] ?: $lead['phone']) : 'For enquiries received by phone, WhatsApp or walk-in', 'active' => 'leads.all'];
$fieldCls = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fieldMsg = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>
<?php if ($errors && !isset($errors['_'])): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <?php if ($duplicates): ?>
    <section class="panel dup-panel mb-4">
      <div class="panel-head"><h2><i class="bi bi-exclamation-triangle text-warning me-1"></i> This number already has <?= count($duplicates) ?> open lead<?= count($duplicates) > 1 ? 's' : '' ?></h2></div>
      <div class="panel-body">
        <p class="text-muted-2">To avoid duplicates, add this enquiry to the existing lead — or create a new lead linked to it as a re-enquiry (for a different job).</p>
        <?php foreach ($duplicates as $i => $d): ?>
          <label class="dup-option">
            <input class="form-check-input" type="radio" name="merge_into" value="<?= e($d['lead_number']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
            <span class="mono fw-semibold"><?= e($d['lead_number']) ?></span>
            <span><?= e($d['customer_name'] ?: '—') ?> · <?= e($d['service_name'] ?? 'No service') ?> · <?= e(date('d M Y', strtotime($d['created_at']))) ?></span>
            <?= status_badge($d['status_label'], $d['status_color']) ?>
            <a class="ms-auto small" href="<?= e(lead_url($d['lead_number'])) ?>" target="_blank">Open <i class="bi bi-box-arrow-up-right"></i></a>
          </label>
        <?php endforeach; ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button class="btn btn-grad" type="submit" name="dup_action" value="merge"><i class="bi bi-arrow-repeat"></i> Add enquiry to selected lead</button>
          <button class="btn btn-ghost" type="submit" name="dup_action" value="new"><i class="bi bi-plus-lg"></i> Create new lead as re-enquiry</button>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-8">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Customer</h2></div>
        <div class="panel-body row g-3">
          <div class="col-md-6"><label class="form-label" for="lName">Name *</label><input class="form-control<?= $fieldCls('customer_name') ?>" id="lName" name="customer_name" value="<?= e($values['customer_name']) ?>" maxlength="100" required><?= $fieldMsg('customer_name') ?></div>
          <div class="col-md-6"><label class="form-label" for="lPhone">Mobile *</label><input class="form-control<?= $fieldCls('phone') ?>" id="lPhone" name="phone" value="<?= e($values['phone']) ?>" inputmode="tel" required><?= $fieldMsg('phone') ?></div>
          <div class="col-md-6"><label class="form-label" for="lEmail">Email</label><input class="form-control<?= $fieldCls('email') ?>" id="lEmail" name="email" type="email" value="<?= e($values['email']) ?>" maxlength="150"><?= $fieldMsg('email') ?></div>
          <div class="col-md-6"><label class="form-label" for="lCity">City</label><input class="form-control" id="lCity" name="city" value="<?= e($values['city']) ?>" maxlength="80" list="cityList">
            <datalist id="cityList"><?php foreach (get_locations() as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label" for="lArea">Area</label>
            <select class="form-select" id="lArea" name="location_id"><option value="">—</option>
              <?php foreach (get_locations() as $c): ?><optgroup label="<?= e($c['name']) ?>">
                <?php foreach ($c['areas'] as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (string) $values['location_id'] === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
              </optgroup><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label" for="lPin">Pincode</label><input class="form-control<?= $fieldCls('pincode') ?>" id="lPin" name="pincode" value="<?= e($values['pincode']) ?>" inputmode="numeric" maxlength="6"><?= $fieldMsg('pincode') ?></div>
          <div class="col-12"><label class="form-label" for="lAddr">Address</label><input class="form-control" id="lAddr" name="address" value="<?= e($values['address']) ?>" maxlength="500"></div>
        </div>
      </section>

      <section class="panel mb-4">
        <div class="panel-head"><h2>Request</h2></div>
        <div class="panel-body row g-3">
          <div class="col-md-6"><label class="form-label" for="lSvc">Service</label>
            <select class="form-select<?= $fieldCls('service_id') ?>" id="lSvc" name="service_id"><option value="">—</option>
              <?php foreach (get_categories_with_services() as $c): ?><optgroup label="<?= e($c['name']) ?>">
                <?php foreach ($c['services'] as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (string) $values['service_id'] === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
              </optgroup><?php endforeach; ?>
            </select><?= $fieldMsg('service_id') ?></div>
          <div class="col-md-6"><label class="form-label" for="lProb">Problem</label><input class="form-control" id="lProb" name="problem_type" value="<?= e($values['problem_type']) ?>" maxlength="150" list="probList">
            <datalist id="probList"><?php foreach (array_unique(array_merge(...array_values($problems ?: [[]]))) as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label" for="lApp">Appliance type</label><input class="form-control" id="lApp" name="appliance" value="<?= e($values['appliance']) ?>" maxlength="100" placeholder="e.g. Split AC 1.5 ton"></div>
          <div class="col-md-6"><label class="form-label" for="lBrand">Brand</label><input class="form-control" id="lBrand" name="brand" value="<?= e($values['brand']) ?>" maxlength="60" placeholder="e.g. LG"></div>
          <div class="col-12"><label class="form-label" for="lDesc">Description</label><textarea class="form-control" id="lDesc" name="description" rows="3" maxlength="2000"><?= e($values['description']) ?></textarea></div>
          <div class="col-md-6"><label class="form-label" for="lDate">Preferred date</label><input class="form-control<?= $fieldCls('preferred_date') ?>" id="lDate" name="preferred_date" type="date" value="<?= e($values['preferred_date']) ?>"><?= $fieldMsg('preferred_date') ?></div>
          <div class="col-md-6"><label class="form-label" for="lTime">Preferred time</label>
            <select class="form-select" id="lTime" name="preferred_time"><?= select_options(array_combine(time_slots(), time_slots()), $values['preferred_time'], '—') ?></select></div>
        </div>
      </section>
    </div>

    <div class="col-xl-4">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Lead details</h2></div>
        <div class="panel-body">
          <label class="form-label" for="lSource">Source</label>
          <select class="form-select mb-3" id="lSource" name="source"><?= select_options(LEAD_SOURCES, $values['source']) ?></select>
          <label class="form-label" for="lPrio">Priority</label>
          <select class="form-select mb-4" id="lPrio" name="priority"><?= select_options(LEAD_PRIORITIES, $values['priority']) ?></select>
          <?php if (!$duplicates): ?>
            <button class="btn btn-grad w-100 mb-2" type="submit"><?= $isEdit ? 'Save changes' : 'Create lead' ?></button>
          <?php endif; ?>
          <a class="btn btn-ghost w-100" href="<?= e($isEdit ? lead_url($lead['lead_number']) : url('admin/leads')) ?>">Cancel</a>
          <?php if (!$isEdit): ?><p class="form-text mt-3 mb-0">The phone number is checked for open leads before anything is created.</p><?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
