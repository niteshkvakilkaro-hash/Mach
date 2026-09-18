<?php
/** Follow-up worklist: overdue / today / upcoming / done. Staff without leads.view_all see only their own leads. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('leads.view');

$tabs = ['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Upcoming', 'done' => 'Completed'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'overdue';
$mine = !empty($_GET['mine']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;

[$vis, $params] = lead_visibility('l');
$base = "FROM lead_followups f JOIN leads l ON l.id = f.lead_id
    JOIN lead_statuses st ON st.slug = l.status LEFT JOIN services s ON s.id = l.service_id LEFT JOIN users u ON u.id = f.assigned_to
    WHERE l.deleted_at IS NULL $vis" . ($mine ? ' AND f.assigned_to = ?' : '');
if ($mine) {
    $params[] = $me['id'];
}
$late = "(f.followup_date < CURDATE() OR (f.followup_date = CURDATE() AND f.followup_time IS NOT NULL AND f.followup_time < CURTIME()))";
$conds = [
    'overdue'  => "f.status = 'pending' AND $late",
    'today'    => "f.status = 'pending' AND f.followup_date = CURDATE() AND NOT $late",
    'upcoming' => "f.status = 'pending' AND f.followup_date > CURDATE()",
    'done'     => "f.status <> 'pending'",
];
$counts = [];
foreach ($conds as $k => $c) {
    $counts[$k] = (int) db_value("SELECT COUNT(*) $base AND $c", $params);
}
$order = $tab === 'done' ? 'f.completed_at DESC' : 'f.followup_date, f.followup_time';
$offset = ($page - 1) * $perPage;
$rows = db_all(
    "SELECT f.*, l.lead_number, l.customer_name, l.phone, s.name AS service_name, u.name AS assignee,
            st.label AS status_label, st.color AS status_color
     $base AND {$conds[$tab]}
     ORDER BY $order LIMIT $perPage OFFSET $offset",
    $params
);

$admin = ['title' => 'Follow-ups', 'subtitle' => 'Calls, WhatsApp messages and visits you promised customers', 'active' => 'leads.followups'];
require ROOT_PATH . '/includes/admin/header.php';
$q = ['tab' => $tab, 'mine' => $mine ? 1 : null];
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="status-tabs mb-0">
    <?php foreach ($tabs as $k => $label): ?>
      <a class="stab<?= $tab === $k ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/leads/followups', $q, ['tab' => $k, 'page' => null])) ?>">
        <?php if ($k === 'overdue' && $counts[$k]): ?><i class="dot sb-danger"></i><?php endif; ?><?= e($label) ?> <b><?= number_format($counts[$k]) ?></b>
      </a>
    <?php endforeach; ?>
  </div>
  <a class="btn btn-ghost btn-sm ms-auto" href="<?= e(admin_query_url('admin/leads/followups', $q, ['mine' => $mine ? null : 1, 'page' => null])) ?>">
    <i class="bi <?= $mine ? 'bi-check-square' : 'bi-square' ?>"></i> Only mine
  </a>
</div>

<section class="panel">
  <?php if ($rows): ?>
    <ul class="list-rows">
      <?php foreach ($rows as $f): ?>
        <li>
          <span class="row-icon<?= $tab === 'overdue' ? ' is-late' : '' ?>"><i class="bi <?= $f['type'] === 'whatsapp' ? 'bi-whatsapp' : ($f['type'] === 'visit' ? 'bi-geo-alt' : 'bi-telephone') ?>"></i></span>
          <div class="flex-grow-1 min-w-0">
            <a class="text-reset" href="<?= e(lead_url($f['lead_number'])) ?>"><strong><?= e($f['customer_name'] ?: $f['phone']) ?></strong></a>
            <span class="mono small text-muted-2"><?= e($f['lead_number']) ?></span> <?= status_badge($f['status_label'], $f['status_color']) ?>
            <small class="d-block text-muted-2 text-truncate"><?= e(FOLLOWUP_TYPES[$f['type']] ?? $f['type']) ?><?= $f['note'] ? ' · ' . e($f['note']) : '' ?> · <?= e($f['service_name'] ?? '') ?> · For <?= e($f['assignee'] ?? 'anyone') ?></small>
            <?php if ($f['status'] !== 'pending'): ?><small class="d-block text-muted-2"><?= e(ucfirst($f['status'])) ?><?= $f['outcome'] ? ': ' . e($f['outcome']) : '' ?></small><?php endif; ?>
          </div>
          <div class="text-end small <?= $tab === 'overdue' ? 'text-danger' : 'text-muted-2' ?>">
            <?= e(date('D, d M', strtotime($f['followup_date']))) ?><br><?= $f['followup_time'] ? e(date('h:i A', strtotime($f['followup_time']))) : 'Any time' ?>
          </div>
          <div class="d-flex gap-1">
            <a class="icon-btn icon-btn-sm" href="<?= e(tel_link('+91' . $f['phone'])) ?>" aria-label="Call"><i class="bi bi-telephone"></i></a>
            <?php if ($f['status'] === 'pending' && can('leads.edit')): ?>
              <button class="btn btn-ghost btn-sm" type="button" data-followup-close="<?= (int) $f['id'] ?>" data-status="completed" data-number="<?= e($f['lead_number']) ?>"><i class="bi bi-check2"></i> Done</button>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="panel-foot"><span></span><?= admin_pagination('admin/leads/followups', $q, $page, $counts[$tab], $perPage) ?></div>
  <?php else: ?>
    <div class="empty-mini py-5"><i class="bi bi-check2-all"></i> Nothing here<?= $tab === 'overdue' ? ' — no overdue follow-ups.' : '.' ?></div>
  <?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
