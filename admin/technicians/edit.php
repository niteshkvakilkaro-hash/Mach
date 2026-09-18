<?php
/** Add (no ?id) or edit a technician: profile, photo, services, areas and app login. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$t = $id ? technician_find($id) : null;
$me = require_permission($t ? 'technicians.edit' : 'technicians.create');
if ($id && !$t) {
    flash_set('error', 'Technician not found.');
    redirect(url('admin/technicians'));
}

$values = $t ?: ['name' => '', 'phone' => '', 'email' => '', 'specialization' => '', 'experience_years' => '', 'service_areas' => '', 'address' => '',
                 'status' => 'active', 'joining_date' => date('Y-m-d'), 'commission_percent' => '0', 'service_ids' => [], 'location_ids' => [],
                 'profile_photo' => null, 'user_id' => null, 'login_email' => null, 'login_status' => null];
$loginEnabled = $t && $t['user_id'] && $t['login_status'] === 'active';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $errors['_'] = 'Your session expired. Please submit again.';
    [$data, $services, $areas, $fieldErrors] = technician_validate($_POST, $t['id'] ?? null);
    $errors += $fieldErrors;
    $values = array_merge($values, $data, ['service_ids' => $services, 'location_ids' => $areas]);

    $loginEnabled = !empty($_POST['login_enabled']);
    $loginEmail = clean_str($_POST['login_email'] ?? '', 150);
    $loginPassword = (string) ($_POST['login_password'] ?? '');
    $values['login_email'] = $loginEmail;

    [$photo, $photoError] = !$errors ? upload_image($_FILES['profile_photo'] ?? null, 'technicians', 600) : [null, null];
    if ($photoError) $errors['profile_photo'] = $photoError;

    if (!$errors) {
        if ($photo) $data['profile_photo'] = $photo;
        if (!empty($_POST['remove_photo']) && !$photo) $data['profile_photo'] = null;
        $savedId = technician_save($t, $data, $services, $areas, (int) $me['id']);
        if (($photo || !empty($_POST['remove_photo'])) && $t && $t['profile_photo']) upload_delete($t['profile_photo']);

        $fresh = technician_find($savedId);
        $loginError = null;
        if ($loginEnabled || $fresh['user_id']) {
            $loginError = technician_set_login($fresh, $loginEnabled, $loginEmail ?: ($fresh['email'] ?? ''), $loginPassword !== '' ? $loginPassword : null, (int) $me['id']);
        }
        if ($loginError) {
            flash_set('error', 'Technician saved, but the app login was not updated: ' . $loginError);
            redirect(url('admin/technicians/edit?id=' . $savedId) . '#login');
        }
        flash_set('success', $t ? 'Technician updated.' : 'Technician added.');
        redirect(url('admin/technicians/view?id=' . $savedId));
    }
}

$fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
$admin = ['title' => $t ? 'Edit ' . $t['name'] : 'Add technician', 'active' => 'technicians'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-xl-8">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Profile</h2></div>
        <div class="panel-body row g-3">
          <div class="col-12 d-flex align-items-center gap-3">
            <?php if ($values['profile_photo']): ?><img class="tech-photo tech-photo-lg" src="<?= e(url($values['profile_photo'])) ?>" alt="Current photo"><?php else: ?><span class="avatar tech-photo tech-photo-lg"><?= e(initials($values['name'] ?: '?')) ?></span><?php endif; ?>
            <div class="flex-grow-1">
              <label class="form-label" for="tPhoto">Profile photo</label>
              <input class="form-control<?= $fc('profile_photo') ?>" type="file" id="tPhoto" name="profile_photo" accept="image/jpeg,image/png,image/webp"><?= $fm('profile_photo') ?>
              <div class="form-text">JPG, PNG or WEBP, up to <?= round(config('app.upload_max_bytes') / 1048576, 1) ?> MB.</div>
              <?php if ($values['profile_photo']): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="tRm" name="remove_photo" value="1"><label class="form-check-label small" for="tRm">Remove photo</label></div><?php endif; ?>
            </div>
          </div>
          <div class="col-md-6"><label class="form-label" for="tName">Name *</label><input class="form-control<?= $fc('name') ?>" id="tName" name="name" value="<?= e($values['name']) ?>" maxlength="100" required><?= $fm('name') ?></div>
          <div class="col-md-6"><label class="form-label" for="tPhone">Mobile *</label><input class="form-control<?= $fc('phone') ?>" id="tPhone" name="phone" value="<?= e($values['phone']) ?>" inputmode="tel" required><?= $fm('phone') ?></div>
          <div class="col-md-6"><label class="form-label" for="tEmail">Email</label><input class="form-control<?= $fc('email') ?>" id="tEmail" name="email" type="email" value="<?= e($values['email']) ?>" maxlength="150"><?= $fm('email') ?></div>
          <div class="col-md-6"><label class="form-label" for="tSpec">Specialization</label><input class="form-control" id="tSpec" name="specialization" value="<?= e($values['specialization']) ?>" maxlength="255" placeholder="e.g. AC, Refrigerator"></div>
          <div class="col-md-4"><label class="form-label" for="tExp">Experience (years)</label><input class="form-control<?= $fc('experience_years') ?>" id="tExp" name="experience_years" inputmode="numeric" value="<?= e($values['experience_years']) ?>"><?= $fm('experience_years') ?></div>
          <div class="col-md-4"><label class="form-label" for="tJoin">Joining date</label><input class="form-control<?= $fc('joining_date') ?>" id="tJoin" name="joining_date" type="date" value="<?= e($values['joining_date']) ?>"><?= $fm('joining_date') ?></div>
          <div class="col-md-4"><label class="form-label" for="tCom">Commission (%)</label><input class="form-control<?= $fc('commission_percent') ?>" id="tCom" name="commission_percent" inputmode="decimal" value="<?= e(rtrim(rtrim((string) $values['commission_percent'], '0'), '.') ?: '0') ?>"><?= $fm('commission_percent') ?></div>
          <div class="col-12"><label class="form-label" for="tAddr">Home address</label><input class="form-control" id="tAddr" name="address" value="<?= e($values['address']) ?>" maxlength="500"></div>
        </div>
      </section>

      <section class="panel mb-4">
        <div class="panel-head"><h2>Services this technician handles</h2></div>
        <div class="panel-body">
          <?php foreach (get_categories_with_services() as $c): ?>
            <div class="check-group">
              <div class="check-group-title"><i class="bi <?= e($c['icon']) ?>"></i> <?= e($c['name']) ?></div>
              <?php foreach ($c['services'] as $s): ?>
                <label class="check-chip"><input type="checkbox" name="services[]" value="<?= (int) $s['id'] ?>"<?= in_array((int) $s['id'], $values['service_ids'], true) ? ' checked' : '' ?>> <?= e($s['name']) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel mb-4">
        <div class="panel-head"><h2>Service areas</h2></div>
        <div class="panel-body">
          <?php foreach (get_locations() as $city): ?>
            <div class="check-group">
              <div class="check-group-title"><i class="bi bi-buildings"></i> <?= e($city['name']) ?></div>
              <?php foreach ($city['areas'] as $a): ?>
                <label class="check-chip"><input type="checkbox" name="locations[]" value="<?= (int) $a['id'] ?>"<?= in_array((int) $a['id'], $values['location_ids'], true) ? ' checked' : '' ?>> <?= e($a['name']) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <label class="form-label mt-2" for="tAreas">Other areas (text)</label>
          <input class="form-control" id="tAreas" name="service_areas" value="<?= e($values['service_areas']) ?>" maxlength="500" placeholder="e.g. Ajmer Road, Jhotwara outskirts">
        </div>
      </section>
    </div>

    <div class="col-xl-4">
      <section class="panel mb-4">
        <div class="panel-head"><h2>Status</h2></div>
        <div class="panel-body">
          <?php foreach (TECHNICIAN_STATUSES as $k => $label): ?>
            <div class="form-check mb-1"><input class="form-check-input" type="radio" name="status" id="st<?= $k ?>" value="<?= $k ?>"<?= $values['status'] === $k ? ' checked' : '' ?>><label class="form-check-label" for="st<?= $k ?>"><?= e($label) ?></label></div>
          <?php endforeach; ?>
          <div class="form-text">Inactive technicians can't be assigned jobs and can't sign in.</div>
        </div>
      </section>

      <section class="panel mb-4" id="login">
        <div class="panel-head"><h2>Technician app login</h2></div>
        <div class="panel-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="tLogin" name="login_enabled" value="1"<?= $loginEnabled ? ' checked' : '' ?>>
            <label class="form-check-label" for="tLogin">Allow this technician to sign in</label>
          </div>
          <label class="form-label" for="tLEmail">Login email</label>
          <input class="form-control mb-2" id="tLEmail" name="login_email" type="email" value="<?= e($values['login_email'] ?? $values['email']) ?>" autocomplete="off">
          <label class="form-label" for="tLPass"><?= $t && $t['user_id'] ? 'New password (leave blank to keep)' : 'Password' ?></label>
          <input class="form-control" id="tLPass" name="login_password" type="password" autocomplete="new-password" minlength="8">
          <div class="form-text">At least 8 characters with a number. The technician signs in at <span class="mono"><?= e(url('admin/login')) ?></span> and sees only their own jobs.</div>
          <?php if ($t && $t['last_login_at']): ?><div class="form-text">Last sign-in: <?= e(admin_datetime($t['last_login_at'])) ?></div><?php endif; ?>
        </div>
      </section>

      <button class="btn btn-grad w-100 mb-2" type="submit"><?= $t ? 'Save changes' : 'Add technician' ?></button>
      <a class="btn btn-ghost w-100" href="<?= e($t ? url('admin/technicians/view?id=' . $t['id']) : url('admin/technicians')) ?>">Cancel</a>
    </div>
  </div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
