<?php
require __DIR__ . '/includes/bootstrap.php';

$groups = get_categories_with_services();
$page = [
    'title'       => 'All Home Appliance Repair Services',
    'description' => 'AC, chimney, geyser, washing machine, refrigerator, RO, microwave, cooler, TV and electrical appliance repair at your doorstep in ' . setting('city') . '.',
    'canonical'   => 'services',
    'schema'      => [[
        '@context' => 'https://schema.org',
        '@type'    => 'ItemList',
        'itemListElement' => array_map(fn($s, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $s['name'], 'url' => url('services/' . $s['slug']),
        ], get_services(), array_keys(get_services())),
    ]],
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container-xl">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page">Services</li></ol></nav>
    <p class="eyebrow"><i class="bi bi-grid"></i> <?= count(get_services()) ?> services · <?= count($groups) ?> categories</p>
    <h1>Every appliance. <span class="text-grad">One trusted team.</span></h1>
    <p class="lead">Choose a service to see prices, common problems we fix and book a visit in under a minute.</p>
    <div class="hero-chips mt-4 mb-0">
      <?php foreach ($groups as $g): ?><a class="chip" href="#<?= e($g['slug']) ?>"><i class="bi <?= e($g['icon']) ?>"></i> <?= e($g['name']) ?></a><?php endforeach; ?>
    </div>
  </div>
</section>

<?php foreach ($groups as $g): ?>
<section class="section pt-4 pb-5" id="<?= e($g['slug']) ?>">
  <div class="container-xl">
    <div class="d-flex align-items-center gap-3 mb-4">
      <span class="icon-tile icon-tile-sm"><i class="bi <?= e($g['icon']) ?>"></i></span>
      <div>
        <h2 class="h3 mb-1"><?= e($g['name']) ?></h2>
        <p class="text-muted-2 mb-0"><?= e($g['short_description']) ?></p>
      </div>
    </div>
    <div class="svc-grid">
      <?php foreach ($g['services'] as $svc) { require __DIR__ . '/includes/partials/service-card.php'; } ?>
    </div>
  </div>
</section>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/partials/cta.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
