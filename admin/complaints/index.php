<?php
/** Complaint tickets: open (with SLA countdown), overdue, resolved, closed. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('complaints.view');

$views = ['open' => 'Open', 'overdue' => 'Overdue SLA', 'mine' => 'Assigned to me', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'];
$view = array_key_exists($_GET['view'] ?? '', $views) ? $_GET['view'] : 'open';
$q = clean_str($_GET['q'] ?? '', 100);
$cat = array_key_exists($_GET['category'] ?? '', COMPLAINT_CATEGORIES) ? $_GET['category'] : '';
$tech = (int) ($_GET['technician'] ?? 0) ?: '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$cond = [
    'open' => "c.status IN ('open','in_progress','revisit_scheduled')",
    'overdue' => "c.status IN ('open','in_progress') AND c.sla_due_at < NOW()",
    'mine' => "c.assigned_to = " . (int) $me['id'] . " AND c.status IN ('open','in_progress','revisit_scheduled')",
    'resolved' => "c.status = 'resolved'", 'closed' => "c.status IN ('closed','rejected')", 'all' => '1=1',
];
$where = '1=1';
$params = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where .= ' AND (c.complaint_number LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($cat !== '') { $where .= ' AND c.category = ?'; $params[] = $cat; }
if ($tech !== '') { $where .= ' AND c.technician_id = ?'; $params[] = $tech; }
$counts = [];
foreach ($cond as $k => $sql) $counts[$k] = (int) db_value("SELECT COUNT(*) FROM complaints c WHERE $where AND $sql", $params);
$rows = db_all(
    "SELECT c.*, b.booking_number, t.name AS technician_name, u.name AS assigned_name
     FROM complaints c LEFT JOIN bookings b ON b.id = c.booking_id LEFT JOIN technicians t ON t.id = c.technician_id LEFT JOIN users u ON u.id = c.assigned_to
     WHERE $where AND {$cond[$view]}
     ORDER BY FIELD(c.priority, 'urgent', 'high', 'normal'), c.sla_due_at IS NULL, c.sla_due_at, c.id DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$query = ['view' => $view, 'q' => $q, 'category' => $cat, 'technician' => $tech];

/** "in 2 h 10 m" / "overdue 45 m" */
$slaText = function (?string $due) {
    if (!$due) return '';
    $diff = strtotime($due) - time();
    $abs = abs($diff);
    $txt = $abs >= 86400 ? floor($abs / 86400) . ' d' : ($abs >= 3600 ? floor($abs / 3600) . ' h ' . floor(($abs % 3600) / 60) . ' m' : max(1, floor($abs / 60)) . ' m');
    return $diff < 0 ? '<span class="text-danger fw-semibold"><i class="bi bi-alarm"></i> overdue ' . $txt . '</span>' : '<span class="text-muted-2">due in ' . $txt . '</span>';
};

$admin = [
    'title' => 'Complaints', 'subtitle' => 'Every complaint gets an owner, a deadline and a fix', 'active' => 'complaints',
    'actions' => can('complaints.manage') ? '<a class="btn btn-grad btn-sm" href="' . e(url('admin/complaints/new')) . '"><i class="bi bi-plus-lg"></i> Log a complaint</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="status-tabs mb-3">
  <?php foreach ($views as $k => $label): ?>
    <a class="stab<?= $view === $k ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/complaints', $query, ['view' => $k, 'page' => null])) ?>">
      <?php if ($k === 'overdue' && $counts[$k]): ?><i class="dot sb-danger"></i><?php endif; ?><?= e($label) ?> <b><?= $counts[$k] ?></b></a>
  <?php endforeach; ?>
</div>
<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/complaints')) ?>">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="filter-row">
    <div class="filter-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Complaint no., customer or phone" aria-label="Search complaints"></div>
    <select class="form-select" name="category" style="max-width:240px" aria-label="Category"><?= select_options(COMPLAINT_CATEGORIES, $cat, 'All issues') ?></select>
    <select class="form-select" name="technician" style="max-width:200px" aria-label="Technician"><?= select_options(array_column(lead_technician_options(), 'name', 'id'), $tech, 'All technicians') ?></select>
    <button class="btn btn-grad" type="submit">Filter</button>
  </div>
</form>
<section class="panel">
  <?php if ($rows): ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover mb-0 leads-table">
        <thead><tr><th>Complaint</th><th>Customer</th><th>Issue</th><th>Technician</th><th>Status</th><th>Owner / deadline</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $c): $link = url('admin/complaints/view?number=' . $c['complaint_number']); ?>
            <tr>
              <td><a class="mono fw-semibold" href="<?= e($link) ?>"><?= e($c['complaint_number']) ?></a>
                <div class="d-flex gap-1 flex-wrap mt-1"><?= $c['priority'] !== 'normal' ? priority_badge($c['priority']) : '' ?><?= $c['is_warranty'] ? '<span class="tag"><i class="bi bi-shield-check"></i> Warranty</span>' : '' ?></div></td>
              <td><a class="text-reset" href="<?= e($link) ?>"><strong><?= e($c['customer_name']) ?></strong></a><small class="d-block text-muted-2"><?= e($c['phone']) ?> · <?= e(ucfirst($c['source'])) ?> · <?= e(time_ago($c['created_at'])) ?></small></td>
              <td><?= e(COMPLAINT_CATEGORIES[$c['category']]) ?><?php if ($c['booking_number']): ?><small class="d-block text-muted-2 mono"><?= e($c['booking_number']) ?></small><?php endif; ?></td>
              <td><?= e($c['technician_name'] ?? '—') ?></td>
              <td><?= status_badge(COMPLAINT_STATUSES[$c['status']], COMPLAINT_STATUS_COLORS[$c['status']]) ?></td>
              <td><?= $c['assigned_name'] ? e($c['assigned_name']) : '<span class="prio prio-high">Unassigned</span>' ?>
                <small class="d-block"><?= in_array($c['status'], ['open', 'in_progress'], true) ? $slaText($c['sla_due_at']) : '' ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot"><span class="small text-muted-2"><?= $counts[$view] ?> complaint<?= $counts[$view] === 1 ? '' : 's' ?></span><?= admin_pagination('admin/complaints', $query, $page, $counts[$view], $perPage) ?></div>
  <?php else: ?>
    <div class="empty-state"><i class="bi bi-emoji-smile"></i><h2 class="h5"><?= $view === 'open' || $view === 'overdue' ? 'No open complaints' : 'Nothing here' ?></h2><p>Complaints come from the website form, low ratings, or are logged by your team.</p></div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
