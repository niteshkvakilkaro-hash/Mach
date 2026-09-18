<?php
require __DIR__ . '/includes/bootstrap.php';

$mapEmbed = setting('google_maps_embed');
if (!str_starts_with($mapEmbed, 'https://www.google.com/maps/embed')) {
    $mapEmbed = '';
}

$page = [
    'title'       => 'Contact Us',
    'description' => 'Call, WhatsApp or email ' . setting('business_name') . ' for home appliance repair in ' . setting('city') . '. ' . setting('working_hours') . '.',
    'canonical'   => 'contact',
    'schema'      => [local_business_schema()],
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container-xl">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page">Contact</li></ol></nav>
    <p class="eyebrow"><i class="bi bi-chat-dots"></i> We reply fast</p>
    <h1>Let's get your <span class="text-grad">appliance working.</span></h1>
    <p class="lead">Call or WhatsApp for the quickest response, or send us a message and we'll get back within working hours.</p>
    <p class="mt-3"><a class="btn btn-ghost" href="<?= e(url('complaint')) ?>"><i class="bi bi-shield-check"></i> Problem with a past repair? Raise a complaint</a></p>
  </div>
</section>

<section class="section pt-0">
  <div class="container-xl">
    <div class="row g-3 mb-5">
      <div class="col-sm-6 col-lg-3"><a class="contact-card" href="<?= e(tel_link()) ?>"><span class="icon-tile icon-tile-sm"><i class="bi bi-telephone"></i></span><span><small>Call us</small><strong><?= e(setting('phone')) ?></strong></span></a></div>
      <div class="col-sm-6 col-lg-3"><a class="contact-card" href="<?= e(whatsapp_link('Hi, I need help with an appliance.')) ?>" target="_blank" rel="noopener"><span class="icon-tile icon-tile-sm" style="background:var(--wa)"><i class="bi bi-whatsapp"></i></span><span><small>WhatsApp</small><strong>Chat now</strong></span></a></div>
      <div class="col-sm-6 col-lg-3"><a class="contact-card" href="mailto:<?= e(setting('email')) ?>"><span class="icon-tile icon-tile-sm"><i class="bi bi-envelope"></i></span><span><small>Email</small><strong><?= e(setting('email')) ?></strong></span></a></div>
      <div class="col-sm-6 col-lg-3"><div class="contact-card"><span class="icon-tile icon-tile-sm"><i class="bi bi-clock"></i></span><span><small>Working hours</small><strong><?= e(setting('working_hours')) ?></strong></span></div></div>
    </div>

    <div class="row g-5">
      <div class="col-lg-7">
        <div class="glass-card p-4 p-md-5">
          <h2 class="h4 mb-4">Send us a message</h2>
          <form action="<?= e(url('api/leads/create.php')) ?>" method="post" data-lead-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="contact">
            <?php require __DIR__ . '/includes/partials/tracking-fields.php'; ?>
            <div class="form-alert" data-form-alert></div>
            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="ctName">Name <span class="req">*</span></label>
                <input class="form-control" id="ctName" name="customer_name" required minlength="2" maxlength="80" autocomplete="name">
                <div class="invalid-feedback" data-error-for="customer_name">Please enter your name.</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="ctPhone">Mobile <span class="req">*</span></label>
                <input class="form-control" id="ctPhone" name="phone" type="tel" inputmode="tel" required pattern="[0-9+\s\-]{10,16}" autocomplete="tel">
                <div class="invalid-feedback" data-error-for="phone">Enter a valid 10-digit mobile number.</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="ctEmail">Email <small class="opt">Optional</small></label>
                <input class="form-control" id="ctEmail" name="email" type="email" maxlength="150" autocomplete="email">
                <div class="invalid-feedback" data-error-for="email">Enter a valid email.</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="ctSubject">Subject</label>
                <input class="form-control" id="ctSubject" name="subject" maxlength="150" placeholder="e.g. AMC enquiry">
              </div>
              <div class="col-12">
                <label class="form-label" for="ctMsg">Message <span class="req">*</span></label>
                <textarea class="form-control" id="ctMsg" name="description" rows="4" required minlength="5" maxlength="1000"></textarea>
                <div class="invalid-feedback" data-error-for="description">Please write a short message.</div>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="1" id="ctConsent" name="consent" required checked>
                  <label class="form-check-label small text-muted-2" for="ctConsent">I agree to be contacted about my enquiry.</label>
                  <div class="invalid-feedback" data-error-for="consent">Please accept to continue.</div>
                </div>
              </div>
              <div class="col-12"><button class="btn btn-grad btn-lg w-100" type="submit">Send message <i class="bi bi-send"></i></button></div>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="glass-card p-4 mb-3">
          <h2 class="h5"><i class="bi bi-geo-alt text-grad"></i> Visit / service base</h2>
          <p class="text-muted-2 mb-3"><?= e(setting('address')) ?></p>
          <?php if (setting('google_maps_url')): ?><a class="btn btn-ghost btn-sm" href="<?= e(setting('google_maps_url')) ?>" target="_blank" rel="noopener">Open in Google Maps <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
        </div>
        <?php if ($mapEmbed): ?>
          <iframe class="map-frame" src="<?= e($mapEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map"></iframe>
        <?php endif; ?>
        <div class="glass-card p-4">
          <h2 class="h5 mb-3">Areas we serve</h2>
          <?php foreach (get_locations() as $city): ?>
            <div class="areas-list mb-0"><?php foreach ($city['areas'] as $a): ?><span class="chip"><?= e($a['name']) ?></span><?php endforeach; ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
