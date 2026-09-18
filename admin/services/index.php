<?php
/** Services list, grouped by category, with quick show/hide and featured toggles. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('services.view');

// Quick toggles (status / featured)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('services.edit');
    $id = (int) ($_POST['id'] ?? 0);
    $col = ['status' => 'status', 'featured' => 'is_featured'][$_POST['toggle'] ?? ''] ?? null;
    $svc = db_one('SELECT id, name, status, is_featured FROM services WHERE id = ?', [$id]);
    if (csrf_verify() && $svc && $col) {
        if ($col === 'status') {
            db_query("UPDATE services SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        } else {
            db_query('UPDATE services SET is_featured = 1 - is_featured WHERE id = ?', [$id]);
        }
        audit_log((int) $me['id'], 'updated', 'services', $id, "Toggled $col for {$svc['name']}");
        flash_set('success', 'Service updated.');
    }
    redirect(url('admin/services') . (!empty($_POST['back']) ? '?' . preg_replace('/[^a-z0-9=&_-]/i', '', $_POST['back']) : ''));
}

$categoryId = (int) ($_GET['category'] ?? 0);
$q = clean_str($_GET['q'] ?? '', 100);
$where = '1=1';
$params = [];
if ($categoryId) { $where .= ' AND s.category_id = ?'; $params[] = $categoryId; }
if ($q !== '') { $where .= ' AND (s.name LIKE ? OR s.slug LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$rows = db_all(
    "SELECT s.*, c.name AS category_name, c.icon AS category_icon,
            (SELECT COUNT(*) FROM leads l WHERE l.service_id = s.id AND l.deleted_at IS NULL AND l.created_at >= CURDATE() - INTERVAL 30 DAY) AS leads_30d,
            (SELECT COUNT(*) FROM service_prices p WHERE p.service_id = s.id AND p.is_active = 1) AS price_rows
     FROM services s JOIN service_categories c ON c.id = s.category_id
     WHERE $where ORDER BY c.sort_order, s.sort_order, s.name",
    $params
);
$groups = [];
foreach ($rows as $r) $groups[$r['category_name']][] = $r;
$categories = array_column(db_all('SELECT id, name FROM service_categories ORDER BY sort_order, name'), 'name', 'id');
$back = http_build_query(array_filter(['category' => $categoryId ?: null, 'q' => $q ?: null]));

$admin = [
    'title'    => 'Services',
    'subtitle' => count($rows) . ' services · each has its own SEO page on the website',
    'active'   => 'services.list',
    'actions'  => can('services.create') ? '<a class="btn btn-grad btn-sm" href="' . e(url('admin/services/edit' . ($categoryId ? '?category=' . $categoryId : ''))) . '"><i class="bi bi-plus-lg"></i> Add service</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
$toggle = function (array $r, string $what, string $label, bool $on) use ($back) {
    if (!can('services.edit')) return $on ? e($label) : '—';
    return '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="toggle" value="' . $what . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="toggle-btn' . ($on ? ' is-on' : '') . '" type="submit"><i class="bi bi-toggle-' . ($on ? 'on' : 'off') . '"></i> ' . e($label) . '</button></form>';
};
?>
<form class="d-flex flex-wrap gap-2 mb-3" method="get" action="<?= e(url('admin/services')) ?>">
  <div class="filter-search" style="max-width:320px"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search services" aria-label="Search services"></div>
  <select class="form-select" name="category" style="max-width:240px" aria-label="Category" onchange="this.form.submit()"><?= select_options($categories, $categoryId ?: '', 'All categories') ?></select>
</form>

<?php if (!$rows): ?>
  <section class="panel"><div class="empty-state"><i class="bi bi-tools"></i><h2 class="h5">No services found</h2></div></section>
<?php endif; ?>
<?php foreach ($groups as $catName => $list): ?>
  <section class="panel mb-4">
    <div class="panel-head"><h2><i class="bi <?= e($list[0]['category_icon'] ?: 'bi-grid') ?> me-1"></i> <?= e($catName) ?></h2><span class="small text-muted-2"><?= count($list) ?> services</span></div>
    <div class="table-responsive">
      <table class="table admin-table mb-0">
        <thead><tr><th>Service</th><th>Starting price</th><th>Duration / warranty</th><th>Leads (30d)</th><th>Featured</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($list as $s): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($s['image']): ?><img class="crud-thumb crud-thumb-sm" src="<?= e(url($s['image'])) ?>" alt=""><?php else: ?><span class="icon-tile icon-tile-xs"><i class="bi <?= e($s['icon'] ?: 'bi-tools') ?>"></i></span><?php endif; ?>
                  <div><a class="text-reset fw-semibold" href="<?= e(url('admin/services/edit?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a>
                    <small class="d-block"><a class="text-muted-2" href="<?= e(url('services/' . $s['slug'])) ?>" target="_blank" rel="noopener">/services/<?= e($s['slug']) ?> <i class="bi bi-box-arrow-up-right"></i></a></small></div>
                </div>
              </td>
              <td><?= $s['starting_price'] !== null ? e(format_inr($s['starting_price'])) : '—' ?><small class="d-block text-muted-2"><?= (int) $s['price_rows'] ?> price rows</small></td>
              <td><?= e($s['duration'] ?: '—') ?><small class="d-block text-muted-2"><?= e($s['warranty'] ? $s['warranty'] . ' warranty' : '') ?></small></td>
              <td><?= (int) $s['leads_30d'] ?></td>
              <td><?= $toggle($s, 'featured', $s['is_featured'] ? 'Featured' : 'No', (bool) $s['is_featured']) ?></td>
              <td><?= $toggle($s, 'status', $s['status'] === 'active' ? 'Live' : 'Hidden', $s['status'] === 'active') ?></td>
              <td class="text-end"><?php if (can('services.edit')): ?><a class="icon-btn icon-btn-sm" href="<?= e(url('admin/services/edit?id=' . $s['id'])) ?>" aria-label="Edit <?= e($s['name']) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
