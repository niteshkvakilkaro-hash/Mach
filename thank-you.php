<?php
require __DIR__ . '/includes/bootstrap.php';

$ty = $_SESSION['thank_you'] ?? null;
$page = [
    'title'         => 'Thank You',
    'canonical'     => 'thank-you',
    'noindex'       => true,
    'no_exit_popup' => true,
];
$waText = $ty
    ? 'Hi, my request number is ' . $ty['lead_number'] . ($ty['service'] ? ' for ' . $ty['service'] : '') . '.'
    : 'Hi, I just submitted a service request.';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container-xl">
    <div class="glass-card thanks-card">
      <div class="thanks-icon"><i class="bi bi-check2"></i></div>
      <?php if ($ty): ?>
        <h1 class="h2 mb-2"><?= $ty['name'] ? 'Thank you, ' . e(strtok($ty['name'], ' ')) . '!' : 'Thank you!' ?></h1>
        <p class="text-muted-2 mb-0">
          <?php if ($ty['is_existing']): ?>
            We already have your earlier request and have added this one to it. Our team will call you shortly.
          <?php elseif ($ty['form_type'] === 'callback'): ?>
            We'll call you back shortly to understand the problem and suggest the next step.
          <?php else: ?>
            Your request has been received. Our team will call you within 15 minutes (working hours) to confirm the visit.
          <?php endif; ?>
        </p>
        <div class="ref-box"><small>Reference number</small><strong><?= e($ty['lead_number']) ?></strong></div>
        <?php if ($ty['service'] || $ty['date']): ?>
          <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
            <?php if ($ty['service']): ?><span class="meta-pill"><i class="bi bi-tools"></i> <?= e($ty['service']) ?></span><?php endif; ?>
            <?php if ($ty['date']): ?><span class="meta-pill"><i class="bi bi-calendar-event"></i> <?= e(date('D, d M Y', strtotime($ty['date']))) ?></span><?php endif; ?>
            <?php if ($ty['time']): ?><span class="meta-pill"><i class="bi bi-clock"></i> <?= e($ty['time']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <h1 class="h2 mb-2">Thank you!</h1>
        <p class="text-muted-2 mb-4">We've received your request and will be in touch shortly.</p>
      <?php endif; ?>

      <p class="small text-muted-2">Need it faster? Message us on WhatsApp with your reference number.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link($waText)) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp us</a>
        <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> Call</a>
        <a class="btn btn-ghost btn-lg" href="<?= e(url()) ?>">Back to home</a>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
