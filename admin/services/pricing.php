<?php
/** Pricing overview: every service's starting price and price list in one place. Editing happens on the service page. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('services.view');

$services = db_all(
    "SELECT s.id, s.name, s.slug, s.starting_price, s.price_note, s.status, c.name AS category_name
     FROM services s JOIN service_categories c ON c.id = s.category_id ORDER BY c.sort_order, s.sort_order, s.name"
);
$rows = [];
foreach (db_all('SELECT service_id, label, price, price_type, note, is_active FROM service_prices ORDER BY sort_order, id') as $p) {
    $rows[$p['service_id']][] = $p;
}
$admin = ['title' => 'Pricing', 'subtitle' => 'Starting prices and price lists shown on the website', 'active' => 'services.pricing'];
require ROOT_PATH . '/includes/admin/header.php';
$type = ['starting' => 'From', 'fixed' => 'Fixed', 'per_unit' => 'Per unit'];
?>
<section class="panel">
  <div class="table-responsive">
    <table class="table admin-table mb-0">
      <thead><tr><th>Service</th><th>Starting price</th><th>Price list</th><th></th></tr></thead>
      <tbody>
        <?php $lastCat = null; foreach ($services as $s): ?>
          <?php if ($s['category_name'] !== $lastCat): $lastCat = $s['category_name']; ?>
            <tr class="group-row"><td colspan="4"><?= e($lastCat) ?></td></tr>
          <?php endif; ?>
          <tr>
            <td><strong><?= e($s['name']) ?></strong><?= $s['status'] !== 'active' ? ' <span class="tag">Hidden</span>' : '' ?></td>
            <td class="text-nowrap"><?= $s['starting_price'] !== null ? e(format_inr($s['starting_price'])) : '<span class="text-muted-2">—</span>' ?><small class="d-block text-muted-2"><?= e($s['price_note']) ?></small></td>
            <td>
              <?php if (!empty($rows[$s['id']])): ?>
                <ul class="price-mini">
                  <?php foreach ($rows[$s['id']] as $p): ?><li><span><?= e($p['label']) ?></span><strong><?= e($type[$p['price_type']] === 'From' ? 'From ' : '') ?><?= e(format_inr($p['price'])) ?><?= $p['price_type'] === 'per_unit' ? '/unit' : '' ?></strong></li><?php endforeach; ?>
                </ul>
              <?php else: ?><span class="text-muted-2 small">No price list</span><?php endif; ?>
            </td>
            <td class="text-end"><?php if (can('services.edit')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/services/edit?id=' . $s['id'])) ?>#pricing"><i class="bi bi-pencil"></i> Edit prices</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
