<?php
/** Service card. Expects $svc (service row) and optional $cardIcon / $cardDesc overrides. */
$icon = $cardIcon ?? ($svc['icon'] ?: 'bi-tools');
$desc = $cardDesc ?? $svc['short_description'];
$price = $svc['min_price'] ?? $svc['starting_price'];
?>
<article class="svc-card reveal">
  <span class="icon-tile"><i class="bi <?= e($icon) ?>"></i></span>
  <h3 class="svc-title"><a href="<?= e(url('services/' . $svc['slug'])) ?>"><?= e($svc['name']) ?></a></h3>
  <p class="svc-desc"><?= e($desc) ?></p>
  <div class="svc-meta">
    <?php if ($price !== null && $price < PHP_INT_MAX): ?><span class="price">From <strong><?= e(format_inr($price)) ?></strong></span><?php endif; ?>
    <?php if (!empty($svc['warranty'])): ?><span class="chip-sm"><i class="bi bi-shield-check"></i> <?= e($svc['warranty']) ?></span><?php endif; ?>
  </div>
  <div class="svc-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('services/' . $svc['slug'])) ?>">View service</a>
    <a class="btn btn-grad btn-sm" href="<?= e(url('book-service?service=' . $svc['slug'])) ?>">Book now</a>
  </div>
</article>
<?php unset($cardIcon, $cardDesc); ?>
