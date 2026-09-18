<?php
require __DIR__ . '/includes/bootstrap.php';

$page = [
    'title'       => 'About Us',
    'description' => setting('business_name') . ' provides reliable doorstep home appliance repair in ' . setting('city') . ' with verified technicians, transparent pricing and service warranty.',
    'canonical'   => 'about',
];
$values = [
    ['bi-clock-history', 'On time, every time', 'We confirm a slot and keep it. If we are running late, you hear it from us first.'],
    ['bi-cash-coin', 'Honest pricing', 'Inspection first, quotation second, repair only after you say yes.'],
    ['bi-tools', 'Skilled hands', 'Technicians are trained on every major brand and verified before joining.'],
    ['bi-arrow-repeat', 'We stand behind our work', 'If the same problem returns within warranty, we fix it again — free.'],
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container-xl">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page">About</li></ol></nav>
    <p class="eyebrow"><i class="bi bi-stars"></i> About <?= e(setting('business_name')) ?></p>
    <h1>Repairs done right, <span class="text-grad">the first time.</span></h1>
    <p class="lead">We started with one idea: getting an appliance fixed at home should be as easy as ordering food. Today our team of <?= e(setting('technicians_count', '40')) ?>+ technicians has completed over <?= e(number_format((int) setting('jobs_completed', '10000'))) ?> repairs across <?= e(setting('city')) ?>.</p>
  </div>
</section>

<section class="section pt-0">
  <div class="container-xl">
    <div class="stats-strip mt-0 mb-5">
      <div><strong data-count="<?= (int) setting('jobs_completed', '10000') ?>">0</strong><span>+ repairs done</span></div>
      <div><strong data-count="<?= (int) setting('technicians_count', '40') ?>">0</strong><span>+ technicians</span></div>
      <div><strong data-count="<?= (int) setting('years_experience', '5') ?>">0</strong><span>+ years</span></div>
      <div><strong><?= e(setting('rating_value', '4.8')) ?></strong><span>average rating</span></div>
    </div>

    <div class="row g-5 align-items-center">
      <div class="col-lg-6 prose">
        <h2>What we do</h2>
        <p>We repair, service and install the appliances every home depends on — air conditioners, kitchen chimneys, geysers, washing machines, refrigerators, RO purifiers, microwaves, coolers, TVs and more.</p>
        <p>Every booking is tracked from the first call to the final payment, so you always know who is coming, when, and what it will cost.</p>
        <ul>
          <li>Doorstep service across <?= e(implode(', ', array_column(get_locations(), 'name'))) ?></li>
          <li>Genuine and OEM-quality spare parts</li>
          <li>Warranty on repairs and installations</li>
          <li>Cash, UPI, card or bank transfer after the job</li>
        </ul>
      </div>
      <div class="col-lg-6">
        <div class="why-grid" style="grid-template-columns:1fr 1fr">
          <?php foreach ($values as [$icon, $title, $text]): ?>
            <div class="why-card reveal"><i class="bi <?= $icon ?>"></i><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/partials/cta.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
