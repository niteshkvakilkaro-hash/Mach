<?php
/**
 * Add / edit a service: details, image, page content, common problems, price list and SEO.
 * Every active service automatically gets its page at /services/{slug} and a sitemap entry.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';

$id = (int) ($_GET['id'] ?? 0);
$svc = $id ? db_one('SELECT * FROM services WHERE id = ?', [$id]) : null;
$me = require_permission($svc ? 'services.edit' : 'services.create');
if ($id && !$svc) {
    flash_set('error', 'Service not found.');
    redirect(url('admin/services'));
}
$categories = array_column(db_all('SELECT id, name FROM service_categories ORDER BY sort_order, name'), 'name', 'id');

$fields = [
    'category_id'       => ['label' => 'Category', 'type' => 'select', 'required' => true, 'options' => $categories, 'col' => 6],
    'name'              => ['label' => 'Service name', 'required' => true, 'max' => 120, 'col' => 6, 'placeholder' => 'e.g. AC Repair'],
    'slug'              => ['label' => 'Page URL', 'type' => 'slug', 'from' => 'name', 'unique' => true, 'max' => 140, 'col' => 6, 'help' => 'Leave empty to create from the name. Changing it later breaks old links.'],
    'icon'              => ['label' => 'Icon', 'max' => 60, 'col' => 6, 'placeholder' => 'bi-snow2'],
    'short_description' => ['label' => 'Short description', 'max' => 300, 'help' => 'One line, shown on service cards and under the page title.'],
    'description'       => ['label' => 'Page content', 'type' => 'textarea', 'max' => 20000, 'rows' => 10,
                            'help' => 'Start a line with "## " for a heading and "- " for a bullet point. Empty line = new paragraph.'],
    'starting_price'    => ['label' => 'Starting price (₹)', 'type' => 'decimal', 'min' => 0, 'max' => 1000000, 'col' => 6],
    'price_note'        => ['label' => 'Price note', 'max' => 100, 'col' => 6, 'placeholder' => 'visit + inspection'],
    'duration'          => ['label' => 'Duration', 'max' => 50, 'col' => 6, 'placeholder' => '60–90 min'],
    'warranty'          => ['label' => 'Warranty', 'max' => 50, 'col' => 6, 'placeholder' => '30 days', 'help' => 'Number of days is used for the booking warranty date.'],
    'is_featured'       => ['label' => 'Featured on home page', 'type' => 'checkbox', 'col' => 4],
    'is_emergency'      => ['label' => 'Emergency service', 'type' => 'checkbox', 'col' => 4],
    'image'             => ['label' => 'Image', 'type' => 'image', 'folder' => 'services', 'max_side' => 1200],
    'seo_title'         => ['label' => 'SEO title', 'max' => 160, 'col' => 6, 'help' => 'Aim for 50–60 characters. Empty = “Service in City”.'],
    'seo_keywords'      => ['label' => 'SEO keywords', 'max' => 255, 'col' => 6],
    'seo_description'   => ['label' => 'SEO description', 'type' => 'textarea', 'max' => 320, 'rows' => 2, 'help' => 'Aim for 140–160 characters. Shown in Google results.'],
];

$values = $svc ?: ['category_id' => (int) ($_GET['category'] ?? 0) ?: '', 'is_featured' => 0, 'is_emergency' => 0, 'status' => 'active'] + array_fill_keys(array_keys($fields), '');
$problems = $svc ? implode("\n", array_column(db_all('SELECT name FROM service_problems WHERE service_id = ? ORDER BY sort_order, id', [$svc['id']]), 'name')) : '';
$prices = $svc ? db_all('SELECT label, price, price_type, note FROM service_prices WHERE service_id = ? ORDER BY sort_order, id', [$svc['id']]) : [];
$errors = [];
$cfg = ['table' => 'services', 'fields' => $fields];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_'] = 'Your session expired. Please submit again.';
    }
    $op = $_POST['op'] ?? 'save';

    if ($op === 'delete' && !$errors && $svc) {
        require_permission('services.delete');
        $inUse = (int) db_value('SELECT (SELECT COUNT(*) FROM leads WHERE service_id = ?) + (SELECT COUNT(*) FROM bookings WHERE service_id = ?)', [$svc['id'], $svc['id']]);
        if ($inUse) {
            flash_set('error', "“{$svc['name']}” is linked to $inUse leads/bookings, so it can't be deleted. Hide it instead (Status → Hidden).");
            redirect(url('admin/services/edit?id=' . $svc['id']));
        }
        db_query('DELETE FROM services WHERE id = ?', [$svc['id']]);
        upload_delete($svc['image']);
        audit_log((int) $me['id'], 'deleted', 'services', (int) $svc['id'], "Deleted service {$svc['name']}", $svc);
        flash_set('success', 'Service deleted.');
        redirect(url('admin/services'));
    }

    [$data, $fieldErrors] = crud_validate($cfg, $_POST, $svc);
    $errors += $fieldErrors;
    if ($data['icon'] !== null && !preg_match('/^bi-[a-z0-9-]+$/', $data['icon'])) $errors['icon'] = 'Use an icon name like bi-snow2.';
    $data['status'] = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    // Problems: one per line
    $problems = (string) ($_POST['problems'] ?? '');
    $problemList = array_values(array_unique(array_filter(array_map(fn($l) => clean_str($l, 150), preg_split('/\R/', $problems)))));

    // Price rows
    $prices = [];
    foreach ((array) ($_POST['price_label'] ?? []) as $i => $label) {
        $label = clean_str($label, 150);
        $price = trim((string) ($_POST['price_amount'][$i] ?? ''));
        if ($label === '' && $price === '') continue;
        $row = ['label' => $label, 'price' => $price, 'price_type' => in_array($_POST['price_type'][$i] ?? '', ['fixed', 'starting', 'per_unit'], true) ? $_POST['price_type'][$i] : 'starting',
                'note' => clean_str($_POST['price_note'][$i] ?? '', 255)];
        if ($label === '' || !is_numeric($price) || $price < 0) $errors['prices'] = 'Each price row needs a name and a valid amount.';
        $prices[] = $row;
    }
    if (count(array_unique(array_column($prices, 'label'))) !== count($prices)) $errors['prices'] = 'Price row names must be different.';

    $upload = null;
    if (!$errors) {
        [$upload, $imgError] = upload_image($_FILES['image'] ?? null, 'services', 1200);
        if ($imgError) $errors['image'] = $imgError;
    }
    $values = array_merge($values, $data);

    if (!$errors) {
        if ($upload) $data['image'] = $upload;
        elseif (!empty($_POST['image_remove'])) $data['image'] = null;

        $savedId = db_transaction(function () use ($svc, $data, $problemList, $prices, $me) {
            if ($svc) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                db_query("UPDATE services SET $sets WHERE id = ?", array_merge(array_values($data), [$svc['id']]));
                $sid = (int) $svc['id'];
                audit_log((int) $me['id'], 'updated', 'services', $sid, "Edited service {$data['name']}", array_intersect_key($svc, $data), $data);
            } else {
                $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM services WHERE category_id = ?', [$data['category_id']]);
                $sid = db_insert('services', $data);
                audit_log((int) $me['id'], 'created', 'services', $sid, "Created service {$data['name']}");
            }
            db_query('DELETE FROM service_problems WHERE service_id = ?', [$sid]);
            foreach ($problemList as $i => $p) db_insert('service_problems', ['service_id' => $sid, 'name' => $p, 'sort_order' => $i + 1]);
            db_query('DELETE FROM service_prices WHERE service_id = ?', [$sid]);
            foreach ($prices as $i => $p) db_insert('service_prices', ['service_id' => $sid, 'label' => $p['label'], 'price' => round((float) $p['price'], 2),
                'price_type' => $p['price_type'], 'note' => $p['note'] ?: null, 'sort_order' => $i + 1]);
            return $sid;
        });
        if ($svc && $svc['image'] && array_key_exists('image', $data) && $data['image'] !== $svc['image']) upload_delete($svc['image']);
        flash_set('success', 'Service saved. The website page updates immediately.');
        redirect(url('admin/services/edit?id=' . $savedId));
    } elseif ($upload) {
        upload_delete($upload);
    }
}

$admin = [
    'title'    => $svc ? $svc['name'] : 'New service',
    'subtitle' => $svc ? 'Page: /services/' . $svc['slug'] : 'Creates a new page on the website',
    'active'   => 'services.list',
    'actions'  => $svc ? '<a class="btn btn-ghost btn-sm" href="' . e(url('services/' . $svc['slug'])) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> View page</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
$field = fn(string $n) => '<div class="col-md-' . (int) ($fields[$n]['col'] ?? 12) . '">' . crud_field_html($n, $fields[$n], $values[$n] ?? '', $errors[$n] ?? null) . '</div>';
$priceRows = array_merge($prices, array_fill(0, max(2, 5 - count($prices)), ['label' => '', 'price' => '', 'price_type' => 'starting', 'note' => '']));
?>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?><input type="hidden" name="op" value="save">
  <div class="row g-4">
    <div class="col-xl-8">
      <section class="panel mb-4"><div class="panel-head"><h2>Details</h2></div>
        <div class="panel-body row g-3"><?= $field('category_id') . $field('name') . $field('slug') . $field('icon') . $field('short_description') . $field('description') ?></div></section>

      <section class="panel mb-4"><div class="panel-head"><h2>Problems we fix</h2></div>
        <div class="panel-body">
          <label class="form-label" for="problems">One per line</label>
          <textarea class="form-control" id="problems" name="problems" rows="6" placeholder="Not cooling&#10;Water leakage&#10;Gas leak"><?= e($problems) ?></textarea>
          <div class="form-text">Shown on the service page and in the “Problem” dropdown of the booking form.</div>
        </div></section>

      <section class="panel mb-4" id="pricing"><div class="panel-head"><h2>Price list</h2><span class="small text-muted-2">Shown as a table on the service page</span></div>
        <div class="panel-body">
          <?php if (isset($errors['prices'])): ?><div class="alert alert-danger py-2 small"><?= e($errors['prices']) ?></div><?php endif; ?>
          <div class="price-rows" data-price-rows>
            <?php foreach ($priceRows as $p): ?>
              <div class="price-row">
                <input class="form-control" name="price_label[]" value="<?= e($p['label']) ?>" placeholder="e.g. Split AC gas refill (1.5 ton)" aria-label="Price item" maxlength="150">
                <input class="form-control" name="price_amount[]" value="<?= e($p['price'] !== '' ? rtrim(rtrim((string) $p['price'], '0'), '.') : '') ?>" placeholder="₹" inputmode="decimal" aria-label="Price">
                <select class="form-select" name="price_type[]" aria-label="Price type"><?= select_options(['starting' => 'From', 'fixed' => 'Fixed', 'per_unit' => 'Per unit'], $p['price_type']) ?></select>
                <input class="form-control" name="price_note[]" value="<?= e($p['note']) ?>" placeholder="Note (optional)" aria-label="Note" maxlength="255">
              </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-ghost btn-sm mt-2" type="button" data-add-price-row><i class="bi bi-plus-lg"></i> Add row</button>
          <div class="form-text">Empty rows are ignored.</div>
        </div></section>

      <section class="panel mb-4"><div class="panel-head"><h2>SEO</h2></div>
        <div class="panel-body row g-3"><?= $field('seo_title') . $field('seo_keywords') . $field('seo_description') ?>
          <div class="col-12">
            <div class="serp" aria-label="Google preview">
              <small><?= e(parse_url(config('app.url'), PHP_URL_HOST)) ?> › services › <?= e($values['slug'] ?: 'your-service') ?></small>
              <strong data-serp-title><?= e($values['seo_title'] ?: (($values['name'] ?: 'Service') . ' in ' . setting('city'))) ?></strong>
              <span data-serp-desc><?= e($values['seo_description'] ?: $values['short_description']) ?></span>
            </div>
          </div>
        </div></section>
    </div>

    <div class="col-xl-4">
      <section class="panel mb-4"><div class="panel-head"><h2>Status</h2></div>
        <div class="panel-body">
          <div class="form-check mb-1"><input class="form-check-input" type="radio" name="status" id="stA" value="active"<?= ($values['status'] ?? 'active') === 'active' ? ' checked' : '' ?>><label class="form-check-label" for="stA">Live on website</label></div>
          <div class="form-check mb-3"><input class="form-check-input" type="radio" name="status" id="stI" value="inactive"<?= ($values['status'] ?? '') === 'inactive' ? ' checked' : '' ?>><label class="form-check-label" for="stI">Hidden</label></div>
          <div class="row g-2"><?= $field('is_featured') . $field('is_emergency') ?></div>
        </div></section>
      <section class="panel mb-4"><div class="panel-head"><h2>Price &amp; time</h2></div>
        <div class="panel-body row g-3">
          <?php foreach (['starting_price', 'price_note', 'duration', 'warranty'] as $n): ?><?= $field($n) ?><?php endforeach; ?>
        </div></section>
      <section class="panel mb-4"><div class="panel-body"><?= crud_field_html('image', $fields['image'], $values['image'] ?? '', $errors['image'] ?? null) ?></div></section>

      <button class="btn btn-grad w-100 mb-2" type="submit"><?= $svc ? 'Save changes' : 'Create service' ?></button>
      <a class="btn btn-ghost w-100 mb-3" href="<?= e(url('admin/services')) ?>">Back to services</a>
    </div>
  </div>
</form>
<?php if ($svc && can('services.delete')): ?>
  <form method="post" data-confirm="Delete “<?= e($svc['name']) ?>” permanently? Its page, problems, prices and FAQs are removed.">
    <?= csrf_field() ?><input type="hidden" name="op" value="delete">
    <button class="btn btn-link text-danger btn-sm" type="submit"><i class="bi bi-trash"></i> Delete service</button>
  </form>
<?php endif; ?>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
