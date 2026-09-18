<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('leads.view');

$filters = lead_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$result = lead_search($filters, $page, $perPage);
$counts = lead_status_counts($filters);
$statuses = lead_statuses();
$staff = lead_staff_options();
$techs = lead_technician_options();
$services = array_column(get_services(), 'name', 'id');
$activeFilters = count(array_filter(array_diff_key($filters, ['status' => 1, 'q' => 1]), fn($v) => $v !== ''));

$navKey = ['new' => 'leads.new', 'scheduled' => 'leads.scheduled', 'completed' => 'leads.completed', 'cancelled' => 'leads.cancelled'][$filters['status']] ?? 'leads.all';
$title = $filters['status'] && $filters['status'] !== 'open' ? $statuses[$filters['status']]['label'] . ' leads' : ($filters['status'] === 'open' ? 'Open leads' : 'All leads');

$actions = '';
if (can('leads.export')) {
    $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(admin_query_url('admin/leads/export', $filters)) . '"><i class="bi bi-download"></i> Export CSV</a>';
}
if (can('leads.create')) {
    $actions .= '<a class="btn btn-grad btn-sm" href="' . e(url('admin/leads/edit')) . '"><i class="bi bi-plus-lg"></i> New lead</a>';
}
$admin = [
    'title'    => $title,
    'subtitle' => number_format($result['total']) . ' ' . ($result['total'] === 1 ? 'lead' : 'leads') . ($filters['q'] !== '' ? ' matching “' . $filters['q'] . '”' : ''),
    'active'   => $navKey,
    'actions'  => $actions,
];

$allTotal = array_sum($counts);
$openTotal = 0;
foreach ($counts as $slug => $n) {
    if (!(int) ($statuses[$slug]['is_closed'] ?? 1)) $openTotal += $n;
}
require ROOT_PATH . '/includes/admin/header.php';
?>

<!-- Status tabs -->
<div class="status-tabs mb-3" role="tablist" aria-label="Filter by status">
  <a class="stab<?= $filters['status'] === '' ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/leads', $filters, ['status' => null, 'page' => null])) ?>">All <b><?= number_format($allTotal) ?></b></a>
  <a class="stab<?= $filters['status'] === 'open' ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/leads', $filters, ['status' => 'open', 'page' => null])) ?>">Open <b><?= number_format($openTotal) ?></b></a>
  <?php foreach ($statuses as $slug => $s): if (!($counts[$slug] ?? 0) && $filters['status'] !== $slug) continue; ?>
    <a class="stab<?= $filters['status'] === $slug ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/leads', $filters, ['status' => $slug, 'page' => null])) ?>">
      <i class="dot sb-<?= e($s['color']) ?>"></i><?= e($s['label']) ?> <b><?= number_format($counts[$slug] ?? 0) ?></b>
    </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/leads')) ?>">
  <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
  <div class="filter-row">
    <div class="filter-search">
      <i class="bi bi-search"></i>
      <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, phone, lead no. or email" aria-label="Search leads">
    </div>
    <button class="btn btn-ghost" type="button" data-bs-toggle="collapse" data-bs-target="#moreFilters" aria-expanded="<?= $activeFilters ? 'true' : 'false' ?>">
      <i class="bi bi-sliders"></i> Filters<?= $activeFilters ? ' <span class="count-pill">' . $activeFilters . '</span>' : '' ?>
    </button>
    <button class="btn btn-grad" type="submit">Search</button>
    <?php if ($activeFilters || $filters['q'] !== ''): ?><a class="btn btn-link btn-sm" href="<?= e(admin_query_url('admin/leads', ['status' => $filters['status']])) ?>">Clear</a><?php endif; ?>
  </div>
  <div class="collapse<?= $activeFilters ? ' show' : '' ?>" id="moreFilters">
    <div class="row g-2 pt-3">
      <div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="fService">Service</label><select class="form-select form-select-sm" id="fService" name="service"><?= select_options($services, $filters['service'], 'Any') ?></select></div>
      <div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="fSource">Source</label><select class="form-select form-select-sm" id="fSource" name="source"><?= select_options(LEAD_SOURCES, $filters['source'], 'Any') ?></select></div>
      <div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="fAssigned">Assigned staff</label><select class="form-select form-select-sm" id="fAssigned" name="assigned"><?= select_options(['none' => 'Unassigned'] + array_column($staff, 'name', 'id'), $filters['assigned'], 'Anyone') ?></select></div>
      <div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="fTech">Technician</label><select class="form-select form-select-sm" id="fTech" name="technician"><?= select_options(['none' => 'Not assigned'] + array_column($techs, 'name', 'id'), $filters['technician'], 'Any') ?></select></div>
      <div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="fPrio">Priority</label><select class="form-select form-select-sm" id="fPrio" name="priority"><?= select_options(LEAD_PRIORITIES, $filters['priority'], 'Any') ?></select></div>
      <div class="col-6 col-md-4 col-xl-2">
        <label class="form-label" for="fFrom">Created</label>
        <div class="d-flex gap-1">
          <input class="form-control form-control-sm" type="date" id="fFrom" name="from" value="<?= e($filters['from']) ?>" aria-label="From date">
          <input class="form-control form-control-sm" type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To date">
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Table -->
<section class="panel">
  <?php if ($result['rows']): ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover mb-0 leads-table">
        <thead>
          <tr>
            <th>Lead</th><th>Customer / location</th><th>Service / source</th><th>Status</th>
            <th>Assigned staff / technician</th><th>Created</th><th class="text-end"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $l):
              $view = lead_url($l['lead_number']);
              $wa = whatsapp_link(lead_whatsapp_text($l), '91' . $l['phone']); ?>
            <tr>
              <td>
                <a class="mono fw-semibold" href="<?= e($view) ?>"><?= e($l['lead_number']) ?></a>
                <div class="d-flex gap-1 flex-wrap mt-1"><?= priority_badge($l['priority']) ?>
                  <?php if ($l['enquiry_count'] > 1): ?><span class="tag" title="Enquiries from this customer on this lead"><i class="bi bi-arrow-repeat"></i> ×<?= (int) $l['enquiry_count'] ?></span><?php endif; ?>
                  <?php if ($l['is_repeat_customer']): ?><span class="tag" title="Has earlier leads or bookings">Repeat</span><?php endif; ?>
                </div>
              </td>
              <td><a class="text-reset" href="<?= e($view) ?>"><strong><?= e($l['customer_name'] ?: 'No name') ?></strong></a>
                <small class="d-block text-muted-2"><?= e($l['phone']) ?><?= $l['area_name'] || $l['city'] ? ' · ' . e($l['area_name'] ?? $l['city']) : '' ?></small></td>
              <td><?= e($l['service_name'] ?? '—') ?><small class="d-block text-muted-2"><?= e(LEAD_SOURCES[$l['source']] ?? $l['source']) ?> · <?= e(ucfirst($l['form_type'])) ?></small></td>
              <td><?= status_badge($l['status_label'], $l['status_color']) ?></td>
              <td><?= $l['assigned_name'] ? e($l['assigned_name']) : '<span class="text-muted-2">Unassigned</span>' ?>
                <small class="d-block text-muted-2"><i class="bi bi-person-gear"></i> <?= e($l['technician_name'] ?? 'No technician') ?></small></td>
              <td class="text-nowrap"><?= e(date('d M Y', strtotime($l['created_at']))) ?><small class="d-block text-muted-2"><?= e(date('h:i A', strtotime($l['created_at']))) ?></small></td>
              <td class="text-end text-nowrap">
                <a class="icon-btn icon-btn-sm" href="<?= e(tel_link('+91' . $l['phone'])) ?>" title="Call" aria-label="Call <?= e($l['customer_name']) ?>"><i class="bi bi-telephone"></i></a>
                <a class="icon-btn icon-btn-sm icon-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp <?= e($l['customer_name']) ?>"><i class="bi bi-whatsapp"></i></a>
                <div class="dropdown d-inline-block">
                  <button class="icon-btn icon-btn-sm" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="More actions"><i class="bi bi-three-dots-vertical"></i></button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= e($view) ?>"><i class="bi bi-eye me-2"></i>View</a></li>
                    <?php if (can('leads.edit')): ?>
                      <li><a class="dropdown-item" href="<?= e(url('admin/leads/edit?number=' . rawurlencode($l['lead_number']))) ?>"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                      <li><a class="dropdown-item" href="<?= e($view) ?>#status"><i class="bi bi-arrow-left-right me-2"></i>Change status</a></li>
                      <li><a class="dropdown-item" href="<?= e($view) ?>#notes"><i class="bi bi-sticky me-2"></i>Add note</a></li>
                      <li><a class="dropdown-item" href="<?= e($view) ?>#followup"><i class="bi bi-alarm me-2"></i>Add follow-up</a></li>
                    <?php endif; ?>
                    <?php if (can('leads.assign')): ?><li><a class="dropdown-item" href="<?= e($view) ?>#assign"><i class="bi bi-person-check me-2"></i>Assign</a></li><?php endif; ?>
                    <?php if (can('leads.delete')): ?>
                      <li><hr class="dropdown-divider"></li>
                      <li><button class="dropdown-item text-danger" type="button" data-delete-lead="<?= e($l['lead_number']) ?>"><i class="bi bi-trash me-2"></i>Delete</button></li>
                    <?php endif; ?>
                  </ul>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot">
      <span class="text-muted-2 small">Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $result['total'])) ?> of <?= number_format($result['total']) ?></span>
      <?= admin_pagination('admin/leads', $filters, $page, $result['total'], $perPage) ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <h2 class="h4">No leads found</h2>
      <p><?= $filters['q'] !== '' || $activeFilters ? 'Try a different search or clear the filters.' : 'New enquiries from the website will appear here.' ?></p>
      <?php if (can('leads.create')): ?><a class="btn btn-grad" href="<?= e(url('admin/leads/edit')) ?>"><i class="bi bi-plus-lg"></i> Add a lead</a><?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php require ROOT_PATH . '/includes/admin/partials/delete-modal.php'; ?>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
