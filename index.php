<?php
require __DIR__ . '/includes/bootstrap.php';

$faqs = get_faqs(null, 8);
$testimonials = get_testimonials(6);
$cards = get_home_service_cards(8);
$locations = get_locations();

$page = [
    'title'       => setting('seo_default_title'),
    'description' => setting('seo_default_description'),
    'canonical'   => '',
    'schema'      => [local_business_schema(), faq_schema($faqs)],
];

$why = [
    ['bi-person-badge', 'Experienced technicians', 'Background-verified experts with 5+ years on the tools.'],
    ['bi-lightning-charge', 'Quick response', 'Call-back in minutes, visits often the same day.'],
    ['bi-house-door', 'Doorstep service', 'We come to you — no carrying heavy appliances.'],
    ['bi-receipt', 'Transparent pricing', 'Quotation before work. No hidden charges, ever.'],
    ['bi-patch-check', 'Genuine parts', 'OEM-quality spares, old part shown to you.'],
    ['bi-shield-check', 'Service warranty', 'Up to 90 days warranty on repairs and installation.'],
    ['bi-phone', 'Easy booking', 'Book online, on WhatsApp or with one call.'],
    ['bi-headset', 'Customer support', 'A real person to help before and after the visit.'],
];
$steps = [
    ['bi-calendar-plus', 'Book service', 'Online form, call or WhatsApp.'],
    ['bi-chat-square-check', 'Confirmation', 'We call to confirm details and slot.'],
    ['bi-person-gear', 'Technician assigned', 'Nearest expert for your appliance.'],
    ['bi-geo-alt', 'Technician visits', 'On time, with tools and common parts.'],
    ['bi-wrench-adjustable', 'Repair / service', 'Price approved by you, then repaired.'],
    ['bi-credit-card', 'Payment', 'Cash, UPI or card after the job.'],
    ['bi-emoji-smile', 'Completed', 'Tested, cleaned up, warranty active.'],
];
$heroChips = ['AC Repair' => 'ac-repair', 'Geyser Repair' => 'geyser-repair', 'Chimney Cleaning' => 'chimney-cleaning', 'Washing Machine' => 'washing-machine-repair'];

require __DIR__ . '/includes/header.php';
?>

<!-- ============ HERO ============ -->
<section class="hero-wrap">
  <div class="container-xl">
    <div class="hero-panel">
      <div class="hero-grid-bg" aria-hidden="true"></div>
      <div class="row align-items-center g-5 position-relative">
        <div class="col-lg-6">
          <p class="eyebrow reveal"><i class="bi bi-stars"></i> Same-day doorstep repairs in <?= e(setting('city', 'your city')) ?></p>
          <h1 class="hero-title reveal">
            <span class="d-block">Appliance down?</span>
            <span class="text-grad d-block">Fixed today.</span>
          </h1>
          <p class="hero-sub reveal">Professional AC, chimney, geyser, washing machine, refrigerator and home appliance repair at your doorstep — verified technicians, upfront pricing and warranty.</p>

          <form class="hero-search reveal" data-hero-search role="search">
            <i class="bi bi-search"></i>
            <label class="visually-hidden" for="heroSearch">What needs repair?</label>
            <input id="heroSearch" list="heroServiceList" placeholder="What needs repair? e.g. AC not cooling" autocomplete="off">
            <datalist id="heroServiceList">
              <?php foreach (get_services() as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?>
            </datalist>
            <button class="btn btn-violet" type="submit">Book <i class="bi bi-arrow-right"></i></button>
          </form>
          <div class="hero-chips reveal">
            <?php foreach ($heroChips as $label => $slug): ?>
              <a class="chip" href="<?= e(url('services/' . $slug)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="hero-actions reveal">
            <a class="btn btn-grad btn-lg" href="<?= e(url('book-service')) ?>">Book a service <i class="bi bi-arrow-right"></i></a>
            <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> Call now</a>
          </div>
          <div class="hero-trust reveal">
            <span><i class="bi bi-star-fill text-amber"></i> <strong><?= e(setting('rating_value', '4.8')) ?></strong> rated by <?= e(number_format((int) setting('rating_count', '1000'))) ?>+ customers</span>
            <span><i class="bi bi-shield-check"></i> Up to 90-day warranty</span>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="orb-stage" aria-hidden="true">
            <div class="orb-floor"></div>
            <div class="orb-glow"></div>
            <div class="orb-ring"></div>
            <div class="orb-ellipse"></div>
            <div class="orb-line"></div>
            <div class="orb-core">
              <div class="core-plate"></div>
              <div class="core-beam"></div>
              <span class="core-icon"><i class="bi bi-wrench-adjustable"></i></span>
            </div>
            <span class="float-tile t1"><i class="bi bi-snow2"></i></span>
            <span class="float-tile t2"><i class="bi bi-thermometer-sun"></i></span>
            <span class="float-tile t3"><i class="bi bi-droplet-half"></i></span>
            <span class="float-tile t4"><i class="bi bi-cloud-haze2"></i></span>
            <span class="float-chip c1"><i></i>Diagnose</span>
            <span class="float-chip c2"><i></i>Repair</span>
            <span class="float-chip c3"><i></i>Warranty</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Stats strip -->
    <div class="stats-strip">
      <div><strong data-count="<?= (int) setting('jobs_completed', '10000') ?>">0</strong><span>+ repairs done</span></div>
      <div><strong data-count="<?= (int) setting('technicians_count', '40') ?>">0</strong><span>+ verified technicians</span></div>
      <div><strong data-count="<?= (int) setting('years_experience', '5') ?>">0</strong><span>+ years in service</span></div>
      <div><strong>60<small>min</small></strong><span>avg. response time</span></div>
    </div>
  </div>
</section>

<!-- ============ SERVICES ============ -->
<section class="section" id="services">
  <div class="container-xl">
    <div class="section-head reveal">
      <p class="eyebrow"><i class="bi bi-grid"></i> Our services</p>
      <h2>One team for every appliance at home</h2>
      <p>Pick your appliance — we will send the right specialist with the right parts.</p>
    </div>
    <div class="svc-grid">
      <?php foreach ($cards as $svc):
          $cardIcon = $svc['category_icon'];
          $cardDesc = $svc['category_desc'];
          require __DIR__ . '/includes/partials/service-card.php';
      endforeach; ?>
    </div>
    <div class="text-center mt-5">
      <a class="btn btn-ghost btn-lg" href="<?= e(url('services')) ?>">See all <?= count(get_services()) ?> services <i class="bi bi-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ============ QUICK BOOKING ============ -->
<section class="section section-alt" id="book">
  <div class="container-xl">
    <div class="row g-5 align-items-start">
      <div class="col-lg-5">
        <div class="lg-sticky">
          <p class="eyebrow reveal"><i class="bi bi-calendar-check"></i> Book in 60 seconds</p>
          <h2 class="display-title reveal">Tell us what's wrong. <span class="text-grad">We'll handle the rest.</span></h2>
          <p class="text-muted-2 mb-4 reveal">No advance payment. A technician calls to confirm your slot and gives you the price before any work starts.</p>
          <ul class="check-list reveal">
            <li><i class="bi bi-check2-circle"></i> Confirmation call within 15 minutes (working hours)</li>
            <li><i class="bi bi-check2-circle"></i> Upfront quotation — approve before repair</li>
            <li><i class="bi bi-check2-circle"></i> Pay after the job by cash, UPI or card</li>
            <li><i class="bi bi-check2-circle"></i> Warranty on every repair</li>
          </ul>
          <div class="emergency-card reveal">
            <span class="icon-tile icon-tile-sm"><i class="bi bi-lightning-charge-fill"></i></span>
            <div>
              <strong>Emergency repair?</strong>
              <span>Geyser, fridge or AC failed? Call for priority dispatch.</span>
            </div>
            <a class="btn btn-grad btn-sm ms-auto" href="<?= e(tel_link()) ?>">Call</a>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="glass-card p-4 p-md-5 reveal">
          <?php $formId = 'home'; require __DIR__ . '/includes/partials/booking-form.php'; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ WHY US ============ -->
<section class="section">
  <div class="container-xl">
    <div class="section-head reveal">
      <p class="eyebrow"><i class="bi bi-award"></i> Why choose us</p>
      <h2>Repairs you don't have to worry about</h2>
    </div>
    <div class="why-grid">
      <?php foreach ($why as [$icon, $title, $text]): ?>
        <div class="why-card reveal">
          <i class="bi <?= $icon ?>"></i>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="section section-alt">
  <div class="container-xl">
    <div class="section-head reveal">
      <p class="eyebrow"><i class="bi bi-signpost-split"></i> How it works</p>
      <h2>From booking to fixed in 7 simple steps</h2>
    </div>
    <ol class="steps">
      <?php foreach ($steps as $i => [$icon, $title, $text]): ?>
        <li class="step reveal">
          <span class="step-num"><?= $i + 1 ?></span>
          <i class="bi <?= $icon ?>"></i>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- ============ SERVICE AREAS ============ -->
<?php if ($locations): ?>
<section class="section">
  <div class="container-xl">
    <div class="areas-panel reveal">
      <div class="row g-4 align-items-center">
        <div class="col-lg-5">
          <p class="eyebrow"><i class="bi bi-geo-alt"></i> Service areas</p>
          <h2 class="display-title">We're probably already nearby.</h2>
          <p class="text-muted-2 mb-0">Technicians are stationed across the city so we reach you faster. Don't see your area? Call us — we likely cover it.</p>
        </div>
        <div class="col-lg-7">
          <?php foreach ($locations as $city): ?>
            <div class="areas-city"><i class="bi bi-buildings"></i> <?= e($city['name']) ?></div>
            <div class="areas-list">
              <?php foreach ($city['areas'] as $a): ?><span class="chip"><?= e($a['name']) ?></span><?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ TESTIMONIALS ============ -->
<?php if ($testimonials): ?>
<section class="section section-alt">
  <div class="container-xl">
    <div class="section-head reveal">
      <p class="eyebrow"><i class="bi bi-chat-heart"></i> Customer reviews</p>
      <h2>Trusted by <?= e(number_format((int) setting('rating_count', '1000'))) ?>+ happy homes</h2>
    </div>
    <div class="review-grid">
      <?php foreach ($testimonials as $t): ?>
        <figure class="review-card reveal">
          <div class="stars" aria-label="<?= (int) $t['rating'] ?> out of 5 stars">
            <?php for ($i = 1; $i <= 5; $i++): ?><i class="bi bi-star<?= $i <= (int) $t['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?>
          </div>
          <blockquote>“<?= e($t['review']) ?>”</blockquote>
          <figcaption>
            <?php if ($t['photo']): ?><img class="avatar" src="<?= e(url($t['photo'])) ?>" alt="" loading="lazy" width="42" height="42"><?php else: ?><span class="avatar"><?= e(initials($t['customer_name'])) ?></span><?php endif; ?>
            <span><strong><?= e($t['customer_name']) ?></strong><small><?= e($t['location']) ?></small></span>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============ FAQ ============ -->
<?php if ($faqs): ?>
<section class="section">
  <div class="container-xl">
    <div class="row g-5">
      <div class="col-lg-4">
        <p class="eyebrow reveal"><i class="bi bi-question-circle"></i> FAQ</p>
        <h2 class="display-title reveal">Questions, answered.</h2>
        <p class="text-muted-2 reveal">Can't find what you're looking for? Our team is one call away.</p>
        <a class="btn btn-ghost reveal" href="<?= e(whatsapp_link('Hi, I have a question about your services.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Ask on WhatsApp</a>
      </div>
      <div class="col-lg-8">
        <div class="accordion faq" id="faqHome">
          <?php foreach ($faqs as $i => $f): ?>
            <div class="accordion-item reveal">
              <h3 class="accordion-header">
                <button class="accordion-button<?= $i ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="faq<?= $i ?>"><?= e($f['question']) ?></button>
              </h3>
              <div id="faq<?= $i ?>" class="accordion-collapse collapse<?= $i ? '' : ' show' ?>" data-bs-parent="#faqHome">
                <div class="accordion-body"><?= e($f['answer']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/cta.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
