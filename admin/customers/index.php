<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('customers.view');

$q = clean_str($_GET['q'] ?? '', 100);
$sorts = ['recent' => 'Newest', 'spent' => 'Top spenders', 'bookings' => 'Most bookings', 'last' => 'Recently serviced'];
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'recent';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$result = customer_search($q, $sort, $page, $perPage);
$params = ['q' => $q, 'sort' => $sort === 'recent' ? null : $sort];
$showMoney = can('payments.view');

$admin = [
    'title'    => 'Customers',
    'subtitle' => number_format($result['total']) . ' customer' . ($result['total'] === 1 ? '' : 's') . ($q !== '' ? " matching “{$q}”" : ''),
    'active'   => 'customers',
    'actions'  => can('customers.create') ? '<a class="btn btn-grad btn-sm" href="' . e(url('admin/customers/edit')) . '"><i class="bi bi-plus-lg"></i> New customer</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/customers')) ?>">
  <div class="filter-row">
    <div class="filter-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone, email or customer no." aria-label="Search customers"></div>
    <select class="form-select" name="sort" style="max-width:200px" aria-label="Sort"><?= select_options($sorts, $sort) ?></select>
    <button class="btn btn-grad" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-link btn-sm" href="<?= e(url('admin/customers')) ?>">Clear</a><?php endif; ?>
  </div>
</form>

<section class="panel">
  <?php if ($result['rows']): ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover mb-0 leads-table">
        <thead><tr><th>Customer</th><th>Phone</th><th>Location</th><th class="text-end">Bookings</th><?php if ($showMoney): ?><th class="text-end">Spent</th><?php endif; ?><th>Last service</th><th>Since</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($result['rows'] as $c): $link = url('admin/customers/view?number=' . $c['customer_number']); ?>
            <tr>
              <td><span class="avatar avatar-sm"><?= e(initials($c['name'])) ?></span> <a class="text-reset" href="<?= e($link) ?>"><strong><?= e($c['name']) ?></strong></a><small class="d-block text-muted-2 mono ms-5 ps-1"><?= e($c['customer_number']) ?></small></td>
              <td><?= e($c['phone']) ?><?php if ($c['email']): ?><small class="d-block text-muted-2"><?= e($c['email']) ?></small><?php endif; ?></td>
              <td><?= e($c['area_name'] ?? '—') ?><small class="d-block text-muted-2"><?= e($c['city'] ?? '') ?></small></td>
              <td class="text-end"><?= (int) $c['total_bookings'] ?></td>
              <?php if ($showMoney): ?><td class="text-end"><?= e(format_inr($c['total_spent'])) ?></td><?php endif; ?>
              <td><?= $c['last_service_date'] ? e(date('d M Y', strtotime($c['last_service_date']))) : '<span class="text-muted-2">—</span>' ?></td>
              <td class="text-muted-2"><?= e(date('M Y', strtotime($c['created_at']))) ?></td>
              <td class="text-end text-nowrap">
                <a class="icon-btn icon-btn-sm" href="<?= e(tel_link('+91' . $c['phone'])) ?>" aria-label="Call <?= e($c['name']) ?>"><i class="bi bi-telephone"></i></a>
                <a class="icon-btn icon-btn-sm icon-wa" href="<?= e(whatsapp_link('Hi ' . strtok($c['name'], ' ') . ', this is ' . setting('business_name') . '.', '91' . $c['phone'])) ?>" target="_blank" rel="noopener" aria-label="WhatsApp <?= e($c['name']) ?>"><i class="bi bi-whatsapp"></i></a>
                <a class="icon-btn icon-btn-sm" href="<?= e($link) ?>" aria-label="Open customer"><i class="bi bi-chevron-right"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot">
      <span class="text-muted-2 small">Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $result['total'])) ?> of <?= number_format($result['total']) ?></span>
      <?= admin_pagination('admin/customers', $params, $page, $result['total'], $perPage) ?>
    </div>
  <?php else: ?>
    <div class="empty-state"><i class="bi bi-people"></i><h2 class="h4">No customers found</h2><p>Customers are created automatically when a lead is booked.</p></div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
