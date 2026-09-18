<?php
require __DIR__ . '/includes/bootstrap.php';

// ?service=ac-repair preselects a service; ?q= comes from the hero search
$preselect = null;
if (!empty($_GET['service'])) {
    $preselect = get_service_by_slug(preg_replace('/[^a-z0-9-]/', '', strtolower((string) $_GET['service'])));
}
$query = clean_str($_GET['q'] ?? '', 100);

$page = [
    'title'         => 'Book a Service',
    'description'   => 'Book AC, chimney, geyser, washing machine, refrigerator or any home appliance repair online in ' . setting('city') . '. Confirmation call within minutes.',
    'canonical'     => 'book-service',
    'no_exit_popup' => true,
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero pb-5">
  <div class="container-xl">
    <div class="row g-5">
      <div class="col-lg-5">
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page">Book a Service</li></ol></nav>
        <p class="eyebrow"><i class="bi bi-calendar-check"></i> Book in 60 seconds</p>
        <h1>Book a <span class="text-grad">technician visit</span></h1>
        <p class="lead mb-4">Fill in the details and we'll call you to confirm the slot. No advance payment needed.</p>
        <?php if ($query !== '' && !$preselect): ?>
          <div class="alert alert-info small">Looking for “<?= e($query) ?>”? Pick the closest service below or describe it — we'll sort it out.</div>
        <?php endif; ?>

        <ul class="check-list">
          <li><i class="bi bi-check2-circle"></i> Confirmation call within 15 minutes</li>
          <li><i class="bi bi-check2-circle"></i> Verified, experienced technicians</li>
          <li><i class="bi bi-check2-circle"></i> Price approved by you before repair</li>
          <li><i class="bi bi-check2-circle"></i> Service warranty on every job</li>
        </ul>

        <div class="d-grid gap-3">
          <a class="contact-card" href="<?= e(tel_link()) ?>">
            <span class="icon-tile icon-tile-sm"><i class="bi bi-telephone"></i></span>
            <span><small>Prefer to talk?</small><strong><?= e(setting('phone')) ?></strong></span>
          </a>
          <a class="contact-card" href="<?= e(whatsapp_link('Hi, I want to book a service.')) ?>" target="_blank" rel="noopener">
            <span class="icon-tile icon-tile-sm" style="background:var(--wa)"><i class="bi bi-whatsapp"></i></span>
            <span><small>Book on WhatsApp</small><strong>Chat with us</strong></span>
          </a>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="glass-card p-4 p-md-5">
          <?php $formServiceId = $preselect ? (int) $preselect['id'] : null; $formId = 'book'; require __DIR__ . '/includes/partials/booking-form.php'; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
