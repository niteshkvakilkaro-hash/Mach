<?php
/** Public layout footer: footer, sticky mobile actions, callback popup, scripts. */
$businessName = setting('business_name', 'Home Services');
$footerCats = get_categories_with_services();
$socials = array_filter([
    'facebook'  => setting('social_facebook'),
    'instagram' => setting('social_instagram'),
    'youtube'   => setting('social_youtube'),
    'twitter-x' => setting('social_x'),
    'linkedin'  => setting('social_linkedin'),
]);
?>
</main>

<footer class="site-footer">
  <div class="container-xl">
    <div class="row g-5">
      <div class="col-lg-4">
        <a class="brand mb-3" href="<?= e(url()) ?>">
          <span class="brand-mark"><i class="bi bi-tools"></i></span>
          <span class="brand-text">
            <span class="brand-name"><?= e(strtok($businessName, ' ')) ?></span>
            <span class="brand-sub"><?= e(setting('business_tagline', 'Home Services')) ?></span>
          </span>
        </a>
        <p class="text-muted-2 mb-4">Doorstep repair and servicing for AC, chimney, geyser, washing machine, refrigerator, RO and more — by verified technicians with transparent pricing and warranty.</p>
        <div class="d-flex gap-2">
          <?php foreach ($socials as $icon => $href): ?>
            <a class="icon-btn icon-btn-sm" href="<?= e($href) ?>" target="_blank" rel="noopener" aria-label="<?= e(ucfirst($icon)) ?>"><i class="bi bi-<?= e($icon) ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <h3 class="footer-title">Services</h3>
        <ul class="footer-links">
          <?php foreach (array_slice($footerCats, 0, 7) as $c): ?>
            <li><a href="<?= e(url('services/' . $c['services'][0]['slug'])) ?>"><?= e(str_replace(' Services', '', $c['name'])) ?> Repair</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-6 col-lg-2">
        <h3 class="footer-title">Company</h3>
        <ul class="footer-links">
          <li><a href="<?= e(url('about')) ?>">About Us</a></li>
          <li><a href="<?= e(url('services')) ?>">All Services</a></li>
          <li><a href="<?= e(url('book-service')) ?>">Book a Service</a></li>
          <li><a href="<?= e(url('contact')) ?>">Contact</a></li>
          <li><a href="<?= e(url('complaint')) ?>">Complaint / Warranty</a></li>
          <li><a href="<?= e(url('privacy-policy')) ?>">Privacy Policy</a></li>
          <li><a href="<?= e(url('terms')) ?>">Terms</a></li>
          <li><a href="<?= e(url('refund-policy')) ?>">Refund Policy</a></li>
        </ul>
      </div>
      <div class="col-lg-4">
        <h3 class="footer-title">Get in touch</h3>
        <ul class="footer-contact">
          <li><i class="bi bi-telephone"></i><a href="<?= e(tel_link()) ?>"><?= e(setting('phone')) ?></a></li>
          <li><i class="bi bi-whatsapp"></i><a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener">Chat on WhatsApp</a></li>
          <li><i class="bi bi-envelope"></i><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a></li>
          <li><i class="bi bi-geo-alt"></i><span><?= e(setting('address')) ?></span></li>
          <li><i class="bi bi-clock"></i><span><?= e(setting('working_hours')) ?></span></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($businessName) ?>. All rights reserved.</span>
      <span>Serving <?= e(implode(', ', array_column(get_locations(), 'name'))) ?> &amp; nearby areas</span>
    </div>
  </div>
</footer>

<!-- Floating WhatsApp (desktop) -->
<a class="wa-fab d-none d-md-flex" href="<?= e(whatsapp_link('Hi, I need appliance repair service.')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
  <i class="bi bi-whatsapp"></i>
</a>

<!-- Sticky action bar (mobile) -->
<div class="mobile-cta d-md-none" role="navigation" aria-label="Quick actions">
  <a href="<?= e(tel_link()) ?>" class="mc-call"><i class="bi bi-telephone-fill"></i><span>Call</span></a>
  <a href="<?= e(whatsapp_link('Hi, I need appliance repair service.')) ?>" class="mc-wa" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i><span>WhatsApp</span></a>
  <a href="<?= e(url('book-service')) ?>" class="mc-book"><i class="bi bi-calendar-check"></i><span>Book Now</span></a>
</div>

<?php require ROOT_PATH . '/includes/partials/callback-modal.php'; ?>

<script>
window.APP = <?= js_json([
    'baseUrl' => rtrim(config('app.url'), '/'),
    'booking' => booking_form_data(),
]) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</body>
</html>
