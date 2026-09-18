<?php
/** Audit log: who did what, when, from where — with before/after values. Read-only. */
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_permission('audit.view');

$date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
$f = [
    'q'      => clean_str($_GET['q'] ?? '', 100),
    'user'   => (int) ($_GET['user'] ?? 0) ?: '',
    'module' => preg_match('/^[a-z_]{1,50}$/', $_GET['module'] ?? '') ? $_GET['module'] : '',
    'action' => preg_match('/^[a-z_]{1,50}$/', $_GET['action_type'] ?? '') ? $_GET['action_type'] : '',
    'from'   => $date($_GET['from'] ?? ''),
    'to'     => $date($_GET['to'] ?? ''),
];
$where = '1=1';
$params = [];
if ($f['q'] !== '') { $where .= ' AND (a.description LIKE ? OR a.ip_address LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($params, $like, $like); }
if ($f['user'] !== '') { $where .= ' AND a.user_id = ?'; $params[] = $f['user']; }
if ($f['module'] !== '') { $where .= ' AND a.module = ?'; $params[] = $f['module']; }
if ($f['action'] !== '') { $where .= ' AND a.action = ?'; $params[] = $f['action']; }
if ($f['from'] !== '') { $where .= ' AND a.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to'] !== '') { $where .= ' AND a.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$total = (int) db_value("SELECT COUNT(*) FROM audit_logs a WHERE $where", $params);
$rows = db_all(
    "SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE $where ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$users = array_column(db_all('SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.user_id ORDER BY u.name'), 'name', 'id');
$modules = array_column(db_all('SELECT DISTINCT module FROM audit_logs ORDER BY module'), 'module', 'module');
$actions = array_column(db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action', 'action');
$query = ['q' => $f['q'], 'user' => $f['user'], 'module' => $f['module'], 'action_type' => $f['action'], 'from' => $f['from'], 'to' => $f['to']];

/** Pretty, escaped key: old → new list for the details row. */
$diff = function (?string $old, ?string $new): string {
    $o = $old ? (json_decode($old, true) ?: []) : [];
    $n = $new ? (json_decode($new, true) ?: []) : [];
    $keys = array_unique(array_merge(array_keys($o), array_keys($n)));
    if (!$keys) return '';
    $fmt = fn($v) => $v === null ? '∅' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    $html = '<table class="diff-table"><tbody>';
    foreach ($keys as $k) {
        if (in_array($k, ['password_hash'], true)) continue;
        $html .= '<tr><th>' . e($k) . '</th><td>' . (array_key_exists($k, $o) ? '<del>' . e(mb_strimwidth($fmt($o[$k]), 0, 200, '…')) . '</del>' : '') . '</td><td>'
            . (array_key_exists($k, $n) ? '<ins>' . e(mb_strimwidth($fmt($n[$k]), 0, 200, '…')) . '</ins>' : '') . '</td></tr>';
    }
    return $html . '</tbody></table>';
};
$icon = ['created' => 'bi-plus-circle', 'updated' => 'bi-pencil', 'deleted' => 'bi-trash', 'login' => 'bi-box-arrow-in-right', 'logout' => 'bi-box-arrow-right',
         'denied' => 'bi-shield-exclamation', 'assigned' => 'bi-person-check', 'status_changed' => 'bi-arrow-left-right', 'exported' => 'bi-download'];

$admin = ['title' => 'Audit log', 'subtitle' => number_format($total) . ' recorded actions', 'active' => 'audit'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<form class="panel filter-bar mb-3" method="get" action="<?= e(url('admin/audit-log')) ?>">
  <div class="filter-row">
    <div class="filter-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search description or IP" aria-label="Search"></div>
    <select class="form-select" name="user" style="max-width:180px" aria-label="User"><?= select_options($users, $f['user'], 'Anyone') ?></select>
    <select class="form-select" name="module" style="max-width:160px" aria-label="Module"><?= select_options($modules, $f['module'], 'Any module') ?></select>
    <select class="form-select" name="action_type" style="max-width:160px" aria-label="Action"><?= select_options($actions, $f['action'], 'Any action') ?></select>
    <input class="form-control" type="date" name="from" value="<?= e($f['from']) ?>" style="max-width:150px" aria-label="From">
    <input class="form-control" type="date" name="to" value="<?= e($f['to']) ?>" style="max-width:150px" aria-label="To">
    <button class="btn btn-grad" type="submit">Filter</button>
  </div>
</form>

<section class="panel">
  <?php if ($rows): ?>
    <ul class="audit-list">
      <?php foreach ($rows as $a): $d = $diff($a['old_values'], $a['new_values']); ?>
        <li>
          <span class="row-icon<?= $a['action'] === 'denied' || $a['action'] === 'deleted' ? ' is-late' : '' ?>"><i class="bi <?= e($icon[$a['action']] ?? 'bi-dot') ?>"></i></span>
          <div class="flex-grow-1 min-w-0">
            <div><strong><?= e($a['user_name'] ?? 'System / website') ?></strong> <span class="tag"><?= e($a['module']) ?></span> <span class="tag"><?= e($a['action']) ?></span></div>
            <div class="text-break"><?= e($a['description']) ?></div>
            <small class="text-muted-2"><?= e(admin_datetime($a['created_at'])) ?> · IP <?= e($a['ip_address'] ?? '—') ?></small>
            <?php if ($d): ?><details class="mt-1"><summary class="small text-muted-2">Changes</summary><?= $d ?></details><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="panel-foot"><span class="small text-muted-2">Page <?= $page ?> of <?= max(1, (int) ceil($total / $perPage)) ?></span><?= admin_pagination('admin/audit-log', $query, $page, $total, $perPage) ?></div>
  <?php else: ?>
    <div class="empty-state"><i class="bi bi-journal-text"></i><h2 class="h5">No matching entries</h2></div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
