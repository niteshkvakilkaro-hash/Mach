<?php
/**
 * Public rating page, opened from the private link sent after a job: /feedback?t=<token>
 * Shows only the service, date and technician's first name — no phone or address.
 */
require __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
$fb = feedback_by_token($token);

// "Review us on Google" click-through (tracked, then redirect)
if ($fb && ($_GET['go'] ?? '') === 'google') {
    $google = setting('google_review_url');
    if (str_starts_with($google, 'https://')) {
        db_query('UPDATE feedback SET google_clicked_at = COALESCE(google_clicked_at, NOW()) WHERE id = ?', [$fb['id']]);
        redirect($google);
    }
}

$error = null;
if ($fb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int) ($_POST['rating'] ?? 0);
    if (!csrf_verify()) {
        $error = 'Your session expired. Please submit again.';
    } elseif (!rate_limit('feedback_submit', client_ip(), 20, 3600)) {
        $error = 'Too many attempts. Please try again later.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Please tap a star to rate.';
    } elseif ($fb['status'] === 'requested') {
        $res = feedback_submit($fb, $rating, (array) ($_POST['tags'] ?? []), (string) ($_POST['comment'] ?? ''));
        $_SESSION['feedback_complaint'] = $res['complaint']['complaint_number'] ?? null;
        redirect(url('feedback?t=' . $token));
    }
    if (!$error) redirect(url('feedback?t=' . $token));
}

if (!$fb) http_response_code(404);
$page = ['title' => 'Rate your service', 'noindex' => true, 'no_exit_popup' => true, 'canonical' => 'feedback'];
$submitted = $fb && $fb['status'] === 'submitted';
$happy = $submitted && (int) $fb['rating'] >= 4;
$googleUrl = setting('google_review_url');
$complaintNo = $_SESSION['feedback_complaint'] ?? null;
require __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container-xl">
    <div class="glass-card fb-card">
      <?php if (!$fb): ?>
        <div class="text-center">
          <div class="thanks-icon"><i class="bi bi-link-45deg"></i></div>
          <h1 class="h3">This link isn’t valid</h1>
          <p class="text-muted-2">Please open the link exactly as it was sent to you, or contact us.</p>
          <a class="btn btn-wa" href="<?= e(whatsapp_link('Hi, my feedback link is not working.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp us</a>
        </div>

      <?php elseif ($submitted): ?>
        <div class="text-center">
          <div class="thanks-icon"><i class="bi <?= $happy ? 'bi-emoji-smile' : 'bi-heart' ?>"></i></div>
          <h1 class="h3"><?= $happy ? 'Thank you, ' . e(strtok($fb['customer_name'], ' ')) . '!' : 'We’re sorry — we’ll make it right' ?></h1>
          <div class="fb-stars-static" aria-label="<?= (int) $fb['rating'] ?> out of 5 stars"><?php for ($i = 1; $i <= 5; $i++): ?><i class="bi bi-star<?= $i <= (int) $fb['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?></div>
          <?php if ($happy): ?>
            <p class="text-muted-2 mb-4">Your rating means a lot to our team<?= $fb['technician_name'] ? ' — we’ll pass it on to ' . e(strtok($fb['technician_name'], ' ')) : '' ?>.</p>
            <?php if (str_starts_with($googleUrl, 'https://')): ?>
              <p class="mb-3">Could you share it on Google too? It helps other families find honest service.</p>
              <a class="btn btn-grad btn-lg" href="<?= e(url('feedback?t=' . $token . '&go=google')) ?>" rel="noopener"><i class="bi bi-google"></i> Review us on Google</a>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted-2 mb-2">A service manager will call you within <?= (int) setting('complaint_sla_hours', '4') ?> working hours to fix this.</p>
            <?php if ($complaintNo): ?><div class="ref-box"><small>Complaint number</small><strong><?= e($complaintNo) ?></strong></div><?php endif; ?>
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
              <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> Call us now</a>
              <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link('Hi, about my service ' . $fb['booking_number'] . ($complaintNo ? " (complaint $complaintNo)" : '') . '.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <p class="eyebrow justify-content-center w-100"><i class="bi bi-stars"></i> <?= e(setting('business_name')) ?></p>
        <h1 class="h3 text-center mb-1">How was your <?= e($fb['service_name'] ?: 'service') ?>?</h1>
        <p class="text-center text-muted-2 mb-4">
          <?= e(date('d M Y', strtotime($fb['scheduled_date']))) ?><?= $fb['technician_name'] ? ' · Technician: ' . e(strtok($fb['technician_name'], ' ')) : '' ?> · <?= e($fb['booking_number']) ?>
        </p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" data-feedback-form>
          <?= csrf_field() ?>
          <fieldset class="fb-stars" aria-label="Your rating">
            <legend class="visually-hidden">Rate from 1 to 5 stars</legend>
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" required>
              <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="bi bi-star-fill"></i><span class="visually-hidden"><?= $i ?> stars</span></label>
            <?php endfor; ?>
          </fieldset>
          <p class="text-center small text-muted-2 mb-4" data-star-hint>Tap a star</p>

          <div class="fb-tags" data-tags="good">
            <p class="form-label mb-2">What went well?</p>
            <?php foreach (FEEDBACK_TAGS_GOOD as $k => $label): ?><label class="check-pill"><input type="checkbox" name="tags[]" value="<?= $k ?>"> <?= e($label) ?></label><?php endforeach; ?>
          </div>
          <div class="fb-tags" data-tags="bad">
            <p class="form-label mb-2">What went wrong?</p>
            <?php foreach (FEEDBACK_TAGS_BAD as $k => $label): ?><label class="check-pill is-bad"><input type="checkbox" name="tags[]" value="<?= $k ?>"> <?= e($label) ?></label><?php endforeach; ?>
          </div>

          <label class="form-label mt-3" for="fbComment">Anything else? <small class="opt">Optional</small></label>
          <textarea class="form-control mb-4" id="fbComment" name="comment" rows="3" maxlength="1000" placeholder="Tell us more…"></textarea>
          <button class="btn btn-grad btn-lg w-100" type="submit">Submit rating</button>
          <p class="form-foot"><i class="bi bi-shield-check"></i> Only our team sees this. Not happy? A manager will call you.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
