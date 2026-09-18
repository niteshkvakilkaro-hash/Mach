<?php
require __DIR__ . '/includes/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$service = $slug !== '' ? get_service_by_slug($slug) : null;
if (!$service) {
    require __DIR__ . '/404.php';
    exit;
}

$problems = get_service_problems()[$service['id']] ?? [];
$prices = get_service_prices((int) $service['id']);
$faqs = get_faqs((int) $service['id'], 6);
$related = array_values(array_filter(get_services(), fn($s) => (int) $s['category_id'] === (int) $service['category_id'] && $s['id'] !== $service['id']));
if (count($related) < 3) {
    $related = array_merge($related, array_filter(get_services(), fn($s) => $s['is_featured'] && (int) $s['category_id'] !== (int) $service['category_id']));
}
$related = array_slice($related, 0, 4);
$city = setting('city', 'your city');
$callbackServiceId = (int) $service['id'];

$serviceSchema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Service',
    'name'        => $service['name'],
    'serviceType' => $service['name'],
    'description' => $service['short_description'],
    'url'         => url('services/' . $service['slug']),
    'provider'    => ['@type' => 'HomeAndConstructionBusiness', 'name' => setting('business_name'), 'telephone' => setting('phone'), 'url' => url()],
    'areaServed'  => array_map(fn($c) => ['@type' => 'City', 'name' => $c['name']], get_locations()),
];
if ($service['starting_price'] !== null) {
    $serviceSchema['offers'] = ['@type' => 'Offer', 'price' => (float) $service['starting_price'], 'priceCurrency' => 'INR'];
}

$page = [
    'title'       => $service['seo_title'] ?: $service['name'] . ' in ' . $city,
    'description' => $service['seo_description'] ?: $service['short_description'],
    'keywords'    => $service['seo_keywords'],
    'canonical'   => 'services/' . $service['slug'],
    'og_image'    => $service['image'] ? url($service['image']) : null,
    'schema'      => [
        $serviceSchema,
        [
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => url('services')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $service['name'], 'item' => url('services/' . $service['slug'])],
            ],
        ],
        faq_schema($faqs),
    ],
];
if ($page['og_image'] === null) {
    unset($page['og_image']);
}
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container-xl">
    <div class="row g-5">
      <div class="col-lg-6">
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('services')) ?>">Services</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($service['name']) ?></li>
          </ol>
        </nav>
        <p class="eyebrow"><i class="bi <?= e($service['icon'] ?: 'bi-tools') ?>"></i> <?= e($service['category_name']) ?></p>
        <h1><?= e($service['name']) ?> <span class="text-grad">in <?= e($city) ?></span></h1>
        <p class="lead"><?= e($service['short_description']) ?></p>

        <div class="meta-pills">
          <?php if ($service['starting_price'] !== null): ?>
            <span class="meta-pill"><i class="bi bi-tag"></i> From <strong><?= e(format_inr($service['starting_price'])) ?></strong><?php if ($service['price_note']): ?> <small class="text-muted-2">(<?= e($service['price_note']) ?>)</small><?php endif; ?></span>
          <?php endif; ?>
          <?php if ($service['duration']): ?><span class="meta-pill"><i class="bi bi-clock"></i> <?= e($service['duration']) ?></span><?php endif; ?>
          <?php if ($service['warranty']): ?><span class="meta-pill"><i class="bi bi-shield-check"></i> <?= e($service['warranty']) ?> warranty</span><?php endif; ?>
          <span class="meta-pill"><i class="bi bi-star-fill text-amber"></i> <?= e(setting('rating_value', '4.8')) ?> rating</span>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-5">
          <a class="btn btn-grad btn-lg" href="#bookForm">Book <?= e($service['name']) ?> <i class="bi bi-arrow-right"></i></a>
          <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> Call now</a>
          <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link('Hi, I need ' . $service['name'] . '.')) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i></a>
        </div>

        <?php if ($problems): ?>
          <div class="content-block">
            <h2>Problems we fix</h2>
            <div class="problem-grid">
              <?php foreach ($problems as $p): ?><div class="problem-item"><i class="bi bi-check2-circle"></i><?= e($p) ?></div><?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="content-block prose"><?= render_text($service['description']) ?></div>

        <?php if ($prices): ?>
          <div class="content-block">
            <h2>Pricing</h2>
            <div class="glass-card overflow-hidden">
              <table class="table price-table">
                <thead><tr><th>Service</th><th class="text-end">Price</th></tr></thead>
                <tbody>
                  <?php foreach ($prices as $p): ?>
                    <tr>
                      <td><?= e($p['label']) ?><?php if ($p['note']): ?><br><small class="text-muted-2"><?= e($p['note']) ?></small><?php endif; ?></td>
                      <td class="text-end amt"><?= $p['price_type'] === 'starting' ? 'From ' : '' ?><?= e(format_inr($p['price'])) ?><?= $p['price_type'] === 'per_unit' ? ' /unit' : '' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="small text-muted-2 mt-2">Final price is confirmed after inspection. Spare parts are charged at actual cost.</p>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-6">
        <div class="lg-sticky" id="bookForm">
          <div class="glass-card p-4 p-md-5">
            <h2 class="h4 mb-1">Book <?= e($service['name']) ?></h2>
            <p class="text-muted-2 small mb-4">Takes a minute. We'll call to confirm your slot.</p>
            <?php $formServiceId = (int) $service['id']; $formId = 'svc'; require __DIR__ . '/includes/partials/booking-form.php'; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($faqs): ?>
<section class="section pt-4">
  <div class="container-xl">
    <div class="section-head reveal"><p class="eyebrow"><i class="bi bi-question-circle"></i> FAQ</p><h2><?= e($service['name']) ?> — common questions</h2></div>
    <div class="accordion faq mx-auto" id="faqSvc" style="max-width:860px">
      <?php foreach ($faqs as $i => $f): ?>
        <div class="accordion-item">
          <h3 class="accordion-header"><button class="accordion-button<?= $i ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sfaq<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="sfaq<?= $i ?>"><?= e($f['question']) ?></button></h3>
          <div id="sfaq<?= $i ?>" class="accordion-collapse collapse<?= $i ? '' : ' show' ?>" data-bs-parent="#faqSvc"><div class="accordion-body"><?= e($f['answer']) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($related): ?>
<section class="section pt-0">
  <div class="container-xl">
    <h2 class="h3 mb-4">Related services</h2>
    <div class="svc-grid">
      <?php foreach ($related as $svc) { require __DIR__ . '/includes/partials/service-card.php'; } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/cta.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
