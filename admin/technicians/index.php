<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('technicians.view');

$status = array_key_exists($_GET['status'] ?? '', TECHNICIAN_STATUSES) ? $_GET['status'] : '';
$q = clean_str($_GET['q'] ?? '', 100);
$rows = technician_list($status, $q);
$counts = array_column(db_all("SELECT status, COUNT(*) n FROM technicians WHERE deleted_at IS NULL GROUP BY status"), 'n', 'status');

$admin = [
    'title'    => 'Technicians',
    'subtitle' => count($rows) . ' technician' . (count($rows) === 1 ? '' : 's'),
    'active'   => 'technicians',
    'actions'  => can('technicians.create') ? '<a class="btn btn-grad btn-sm" href="' . e(url('admin/technicians/edit')) . '"><i class="bi bi-plus-lg"></i> Add technician</a>' : '',
];
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="status-tabs mb-0">
    <a class="stab<?= $status === '' ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/technicians', ['q' => $q])) ?>">All <b><?= array_sum($counts) ?></b></a>
    <?php foreach (TECHNICIAN_STATUSES as $k => $label): ?>
      <a class="stab<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(admin_query_url('admin/technicians', ['q' => $q, 'status' => $k])) ?>"><i class="dot sb-<?= ['active' => 'success', 'on_leave' => 'warning', 'inactive' => 'secondary'][$k] ?>"></i><?= e($label) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a>
    <?php endforeach; ?>
  </div>
  <form class="filter-search ms-auto" style="max-width:320px" method="get" action="<?= e(url('admin/technicians')) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone, skill or area" aria-label="Search technicians">
  </form>
</div>

<?php if ($rows): ?>
  <div class="tech-grid">
    <?php foreach ($rows as $t): $link = url('admin/technicians/view?id=' . $t['id']); ?>
      <article class="panel tech-card">
        <div class="tech-card-head">
          <?php if ($t['profile_photo']): ?><img class="tech-photo" src="<?= e(url($t['profile_photo'])) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar tech-photo"><?= e(initials($t['name'])) ?></span><?php endif; ?>
          <div class="min-w-0">
            <a class="text-reset" href="<?= e($link) ?>"><strong><?= e($t['name']) ?></strong></a>
            <small class="d-block text-muted-2 text-truncate"><?= e($t['specialization'] ?: 'No specialization set') ?></small>
            <?php $tr = technician_ratings()[$t['id']] ?? null; ?><small class="d-block"><?= $tr ? '<span class="text-warning">★</span> <strong>' . e($tr['avg_rating']) . '</strong> <span class="text-muted-2">(' . (int) $tr['ratings'] . ' rating' . ((int) $tr['ratings'] === 1 ? '' : 's') . ((int) $tr['complaints'] ? ' · ' . (int) $tr['complaints'] . ' complaint' . ((int) $tr['complaints'] === 1 ? '' : 's') : '') . ')</span>' : '<span class="text-muted-2">No ratings yet</span>' ?></small>
          </div>
          <?= status_badge(TECHNICIAN_STATUSES[$t['status']], ['active' => 'success', 'on_leave' => 'warning', 'inactive' => 'secondary'][$t['status']]) ?>
        </div>
        <div class="tech-stats">
          <div><strong><?= (int) $t['today'] ?></strong><span>Today</span></div>
          <div><strong><?= (int) $t['pending'] ?></strong><span>Open jobs</span></div>
          <div><strong><?= (int) $t['month_completed'] ?></strong><span>Done this month</span></div>
        </div>
        <div class="tech-card-foot">
          <small class="text-muted-2"><i class="bi bi-phone"></i> <?= $t['user_id'] && $t['login_status'] === 'active' ? 'App login active' : 'No app login' ?></small>
          <div class="d-flex gap-1">
            <a class="icon-btn icon-btn-sm" href="<?= e(tel_link('+91' . $t['phone'])) ?>" aria-label="Call <?= e($t['name']) ?>"><i class="bi bi-telephone"></i></a>
            <a class="icon-btn icon-btn-sm icon-wa" href="<?= e(whatsapp_link('', '91' . $t['phone'])) ?>" target="_blank" rel="noopener" aria-label="WhatsApp <?= e($t['name']) ?>"><i class="bi bi-whatsapp"></i></a>
            <a class="icon-btn icon-btn-sm" href="<?= e($link) ?>" aria-label="Open"><i class="bi bi-chevron-right"></i></a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <section class="panel"><div class="empty-state"><i class="bi bi-person-gear"></i><h2 class="h4">No technicians found</h2><p>Add your technicians to assign them jobs.</p></div></section>
<?php endif; ?>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
