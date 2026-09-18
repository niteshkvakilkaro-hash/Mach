<?php /** Closing call-to-action band. */ ?>
<section class="section pt-0">
  <div class="container-xl">
    <div class="cta-panel reveal">
      <div class="cta-glow" aria-hidden="true"></div>
      <p class="eyebrow justify-content-center"><i class="bi bi-stars"></i> Fast, fair, guaranteed</p>
      <h2>Need appliance repair?</h2>
      <p>Book in a minute. A verified technician can be at your door today.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <a class="btn btn-grad btn-lg" href="<?= e(url('book-service')) ?>">Book a service <i class="bi bi-arrow-right"></i></a>
        <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> Call now</a>
        <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link('Hi, I need appliance repair service.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>
      </div>
    </div>
  </div>
</section>
