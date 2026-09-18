<?php
/**
 * Reusable booking form.
 * Optional inputs: $formServiceId (int) to preselect a service, $formId (string) unique prefix.
 */
$fid = $formId ?? 'bf';
$selService = null;
foreach (get_services() as $s) {
    if ((int) $s['id'] === (int) ($formServiceId ?? 0)) {
        $selService = $s;
    }
}
$selCategoryId = $selService ? (int) $selService['category_id'] : 0;
$problems = $selService ? (get_service_problems()[$selService['id']] ?? []) : [];
$appliances = [];
foreach (get_categories() as $c) {
    if ((int) $c['id'] === $selCategoryId) {
        $appliances = array_filter(array_map('trim', explode(',', (string) $c['appliance_types'])));
    }
}
$cities = get_locations();
$defaultCity = $cities[0] ?? null;
$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+' . (int) setting('booking_max_days_ahead', '30') . ' days'));
$fallbackError = flash_get('form_error');
?>
<form class="booking-form" action="<?= e(url('api/leads/create.php')) ?>" method="post" data-lead-form data-booking-form novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="form_type" value="booking">
  <?php require ROOT_PATH . '/includes/partials/tracking-fields.php'; ?>
  <div class="form-alert" data-form-alert><?php if ($fallbackError): ?><div class="alert alert-danger"><?= e($fallbackError) ?></div><?php endif; ?></div>

  <div class="bf-step"><span>1</span> What needs fixing?</div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Cat">Category</label>
      <select class="form-select" id="<?= $fid ?>Cat" name="category_id" data-field="category">
        <option value="">All categories</option>
        <?php foreach (get_categories() as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $selCategoryId ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Svc">Service <span class="req">*</span></label>
      <select class="form-select" id="<?= $fid ?>Svc" name="service_id" required data-field="service">
        <option value="">Select service</option>
        <?php foreach (get_services() as $s): if ($selCategoryId && (int) $s['category_id'] !== $selCategoryId) continue; ?>
          <option value="<?= (int) $s['id'] ?>"<?= $selService && (int) $s['id'] === (int) $selService['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="invalid-feedback" data-error-for="service_id">Please choose a service.</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>App">Appliance type</label>
      <select class="form-select" id="<?= $fid ?>App" name="appliance" data-field="appliance">
        <option value="">Select type</option>
        <?php foreach ($appliances as $a): ?><option><?= e($a) ?></option><?php endforeach; ?>
        <option>Other / Not sure</option>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Prob">Problem</label>
      <select class="form-select" id="<?= $fid ?>Prob" name="problem_type" data-field="problem">
        <option value="">Select problem</option>
        <?php foreach ($problems as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
        <option>Other / Not sure</option>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label" for="<?= $fid ?>Desc">Describe the issue <small class="opt">Optional</small></label>
      <textarea class="form-control" id="<?= $fid ?>Desc" name="description" rows="2" maxlength="1000" placeholder="Brand, model, error code or anything that helps the technician"></textarea>
    </div>
  </div>

  <div class="bf-step"><span>2</span> Your details</div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Name">Full name <span class="req">*</span></label>
      <input class="form-control" id="<?= $fid ?>Name" name="customer_name" required minlength="2" maxlength="80" autocomplete="name" placeholder="Your name">
      <div class="invalid-feedback" data-error-for="customer_name">Please enter your name.</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Phone">Mobile number <span class="req">*</span></label>
      <input class="form-control" id="<?= $fid ?>Phone" name="phone" type="tel" inputmode="tel" required pattern="[0-9+\s\-]{10,16}" autocomplete="tel" placeholder="10-digit mobile number">
      <div class="invalid-feedback" data-error-for="phone">Enter a valid 10-digit mobile number.</div>
    </div>
    <div class="col-12">
      <label class="form-label" for="<?= $fid ?>Email">Email <small class="opt">Optional</small></label>
      <input class="form-control" id="<?= $fid ?>Email" name="email" type="email" maxlength="150" autocomplete="email" placeholder="you@example.com">
      <div class="invalid-feedback" data-error-for="email">Enter a valid email.</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>City">City <span class="req">*</span></label>
      <select class="form-select" id="<?= $fid ?>City" name="city" required data-field="city">
        <?php foreach ($cities as $c): ?>
          <option value="<?= e($c['name']) ?>" data-id="<?= (int) $c['id'] ?>"<?= $defaultCity && $c['id'] === $defaultCity['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="invalid-feedback" data-error-for="city">Please select your city.</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Area">Area</label>
      <select class="form-select" id="<?= $fid ?>Area" name="location_id" data-field="area">
        <option value="">Select area</option>
        <?php foreach ($defaultCity['areas'] ?? [] as $a): ?>
          <option value="<?= (int) $a['id'] ?>" data-pincode="<?= e($a['pincode']) ?>"><?= e($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-8">
      <label class="form-label" for="<?= $fid ?>Addr">Address <span class="req">*</span></label>
      <input class="form-control" id="<?= $fid ?>Addr" name="address" required minlength="5" maxlength="500" autocomplete="street-address" placeholder="House no., street, landmark">
      <div class="invalid-feedback" data-error-for="address">Please enter your full address.</div>
    </div>
    <div class="col-sm-4">
      <label class="form-label" for="<?= $fid ?>Pin">Pincode</label>
      <input class="form-control" id="<?= $fid ?>Pin" name="pincode" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" autocomplete="postal-code" placeholder="302021" data-field="pincode">
      <div class="invalid-feedback" data-error-for="pincode">6-digit pincode.</div>
    </div>
  </div>

  <div class="bf-step"><span>3</span> Preferred visit</div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Date">Date <span class="req">*</span></label>
      <input class="form-control" id="<?= $fid ?>Date" name="preferred_date" type="date" required min="<?= $minDate ?>" max="<?= $maxDate ?>" value="<?= $minDate ?>">
      <div class="invalid-feedback" data-error-for="preferred_date">Please choose a date.</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label" for="<?= $fid ?>Time">Time slot <span class="req">*</span></label>
      <select class="form-select" id="<?= $fid ?>Time" name="preferred_time" required>
        <option value="">Select slot</option>
        <?php foreach (time_slots() as $slot): ?><option><?= e($slot) ?></option><?php endforeach; ?>
      </select>
      <div class="invalid-feedback" data-error-for="preferred_time">Please choose a time slot.</div>
    </div>
  </div>

  <div class="form-check mb-4">
    <input class="form-check-input" type="checkbox" value="1" id="<?= $fid ?>Consent" name="consent" required checked>
    <label class="form-check-label small text-muted-2" for="<?= $fid ?>Consent">
      I agree to the <a href="<?= e(url('terms')) ?>" target="_blank">terms</a> and to be contacted by call/WhatsApp about this request.
    </label>
    <div class="invalid-feedback" data-error-for="consent">Please accept to continue.</div>
  </div>

  <button class="btn btn-grad btn-lg w-100" type="submit">Confirm booking <i class="bi bi-arrow-right"></i></button>
  <p class="form-foot"><i class="bi bi-shield-check"></i> No advance payment. Pay after the job is done.</p>
</form>
