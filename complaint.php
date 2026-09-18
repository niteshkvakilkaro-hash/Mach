<?php
/** Public complaint / warranty claim form: /complaint */
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$v = ['customer_name' => '', 'phone' => '', 'email' => '', 'booking_number' => '', 'category' => '', 'description' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    if (!empty($_POST['website'])) {                                  // honeypot
        redirect(url('complaint?sent=1'));
    }
    if (!csrf_verify()) {
        $errors['_'] = 'Your session expired. Please submit again.';
    } elseif (!rate_limit('complaint_ip', client_ip(), 5, 3600)) {
        $errors['_'] = 'Too many complaints from your network. Please call us directly.';
    } else {
        $name = clean_str($v['customer_name'], 100);
        $phone = normalize_phone($v['phone']);
        $email = clean_str($v['email'], 150);
        $bookingNo = strtoupper(clean_str($v['booking_number'], 20));
        $category = array_key_exists($v['category'], COMPLAINT_CATEGORIES) ? $v['category'] : '';
        $desc = clean_str($v['description'], 2000);
        if (mb_strlen($name) < 2) $errors['customer_name'] = 'Please enter your name.';
        if (!$phone) $errors['phone'] = 'Enter the 10-digit mobile number used for the booking.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email or leave it blank.';
        if ($bookingNo !== '' && !preg_match('/^BK-\d{4}-\d{6}$/', $bookingNo)) $errors['booking_number'] = 'Looks like BK-2026-000123 — or leave it blank.';
        if (!$category) $errors['category'] = 'Choose what went wrong.';
        if (mb_strlen($desc) < 10) $errors['description'] = 'Please describe the problem (at least a few words).';
        if (empty($_POST['consent'])) $errors['consent'] = 'Please accept to continue.';
        if (!$errors && !rate_limit('complaint_phone', $phone, 3, 86400)) {
            $errors['_'] = 'We already have your complaint today. Our team will call you — or WhatsApp us for an update.';
        }
        if (!$errors) {
            $c = complaint_create(['booking_number' => $bookingNo ?: null, 'customer_name' => $name, 'phone' => $phone, 'email' => $email ?: null,
                                   'category' => $category, 'description' => $desc, 'source' => 'website'], null);
            $_SESSION['complaint_done'] = ['number' => $c['complaint_number'], 'warranty' => $c['is_warranty'], 'priority' => $c['priority']];
            redirect(url('complaint?sent=1'));
        }
    }
}
$done = !empty($_GET['sent']) ? ($_SESSION['complaint_done'] ?? ['number' => null, 'warranty' => false, 'priority' => 'normal']) : null;

$page = [
    'title' => 'Raise a complaint / warranty claim',
    'description' => 'Not happy with a repair, or the same problem came back? Raise a complaint or warranty claim with ' . setting('business_name') . ' — a service manager will call you back.',
    'canonical' => 'complaint', 'no_exit_popup' => true,
];
$fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero pb-4">
  <div class="container-xl">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page">Complaint / warranty</li></ol></nav>
    <p class="eyebrow"><i class="bi bi-shield-check"></i> We stand behind our work</p>
    <h1>Something not right? <span class="text-grad">We’ll fix it.</span></h1>
    <p class="lead">Tell us what happened. If your repair is under warranty, the revisit is free. A service manager calls you back within <?= (int) setting('complaint_sla_hours', '4') ?> working hours.</p>
  </div>
</section>
<section class="section pt-0">
  <div class="container-xl">
    <div class="row g-5">
      <div class="col-lg-7">
        <div class="glass-card p-4 p-md-5">
          <?php if ($done): ?>
            <div class="text-center">
              <div class="thanks-icon"><i class="bi bi-check2"></i></div>
              <h2 class="h3">Complaint registered</h2>
              <?php if ($done['number']): ?><div class="ref-box"><small>Complaint number</small><strong><?= e($done['number']) ?></strong></div><?php endif; ?>
              <p class="text-muted-2"><?= $done['warranty'] ? 'Your job is under warranty — the revisit will be free. ' : '' ?>Our service manager will call you within <?= (int) ($done['priority'] === 'urgent' ? setting('complaint_sla_hours_urgent', '2') : setting('complaint_sla_hours', '4')) ?> working hours.</p>
              <div class="d-flex flex-wrap justify-content-center gap-2">
                <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link('Hi, I raised complaint ' . ($done['number'] ?? '') . '.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp us</a>
                <a class="btn btn-ghost btn-lg" href="<?= e(url()) ?>">Back to home</a>
              </div>
            </div>
          <?php else: ?>
            <?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please check the highlighted fields.</div><?php endif; ?>
            <form method="post" novalidate>
              <?= csrf_field() ?>
              <div class="hp-field" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
              <div class="row g-3">
                <div class="col-sm-6"><label class="form-label" for="cName">Your name <span class="req">*</span></label><input class="form-control<?= $fc('customer_name') ?>" id="cName" name="customer_name" value="<?= e($v['customer_name']) ?>" maxlength="100" required autocomplete="name"><?= $fm('customer_name') ?></div>
                <div class="col-sm-6"><label class="form-label" for="cPhone">Mobile number <span class="req">*</span></label><input class="form-control<?= $fc('phone') ?>" id="cPhone" name="phone" type="tel" inputmode="tel" value="<?= e($v['phone']) ?>" required placeholder="Number used for the booking" autocomplete="tel"><?= $fm('phone') ?></div>
                <div class="col-sm-6"><label class="form-label" for="cBk">Booking number <small class="opt">Optional</small></label><input class="form-control<?= $fc('booking_number') ?>" id="cBk" name="booking_number" value="<?= e($v['booking_number']) ?>" maxlength="20" placeholder="BK-2026-000123"><?= $fm('booking_number') ?><div class="form-text text-muted-2">On your receipt or confirmation. We’ll find it from your number if you don’t have it.</div></div>
                <div class="col-sm-6"><label class="form-label" for="cEmail">Email <small class="opt">Optional</small></label><input class="form-control<?= $fc('email') ?>" id="cEmail" name="email" type="email" value="<?= e($v['email']) ?>" maxlength="150" autocomplete="email"><?= $fm('email') ?></div>
                <div class="col-12"><label class="form-label" for="cCat">What went wrong? <span class="req">*</span></label>
                  <select class="form-select<?= $fc('category') ?>" id="cCat" name="category" required><option value="">Choose…</option>
                    <?php foreach (COMPLAINT_CATEGORIES as $k => $label): ?><option value="<?= $k ?>"<?= $v['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                  </select><?= $fm('category') ?></div>
                <div class="col-12"><label class="form-label" for="cDesc">Tell us what happened <span class="req">*</span></label><textarea class="form-control<?= $fc('description') ?>" id="cDesc" name="description" rows="4" maxlength="2000" required placeholder="E.g. AC was repaired on 12 Sep but stopped cooling again yesterday"><?= e($v['description']) ?></textarea><?= $fm('description') ?></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input<?= $fc('consent') ?>" type="checkbox" id="cCons" name="consent" value="1" checked><label class="form-check-label small text-muted-2" for="cCons">I agree to be contacted about this complaint.</label><?= $fm('consent') ?></div></div>
                <div class="col-12"><button class="btn btn-grad btn-lg w-100" type="submit">Submit complaint</button></div>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <ul class="check-list">
          <li><i class="bi bi-check2-circle"></i> Call-back from a service manager, not a bot</li>
          <li><i class="bi bi-check2-circle"></i> Free revisit if the job is under warranty</li>
          <li><i class="bi bi-check2-circle"></i> You get a complaint number to track it</li>
        </ul>
        <div class="d-grid gap-3">
          <a class="contact-card" href="<?= e(tel_link()) ?>"><span class="icon-tile icon-tile-sm"><i class="bi bi-telephone"></i></span><span><small>Urgent? Call us</small><strong><?= e(setting('phone')) ?></strong></span></a>
          <a class="contact-card" href="<?= e(whatsapp_link('Hi, I have a complaint about my service.')) ?>" target="_blank" rel="noopener"><span class="icon-tile icon-tile-sm" style="background:var(--wa)"><i class="bi bi-whatsapp"></i></span><span><small>WhatsApp</small><strong>Chat with us</strong></span></a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
