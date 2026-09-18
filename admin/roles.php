<?php
/**
 * Roles & permissions matrix (Super Admin only). Super Admin always has everything.
 * Changes apply on the users' next page load (permissions are read fresh on every request).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_permission('roles.manage');

$roles = db_all('SELECT id, name, slug FROM roles ORDER BY id');
$perms = db_all('SELECT id, module, action, slug, label FROM permissions ORDER BY id');
$granted = [];
foreach (db_all('SELECT role_id, permission_id FROM role_permissions') as $rp) $granted[$rp['role_id']][$rp['permission_id']] = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect(url('admin/roles'));
    }
    $posted = (array) ($_POST['perm'] ?? []);
    $validPerm = array_flip(array_map('intval', array_column($perms, 'id')));
    $changes = [];
    db_transaction(function () use ($roles, $posted, $validPerm, $granted, &$changes) {
        foreach ($roles as $role) {
            if ($role['slug'] === 'super_admin') continue;
            $want = [];
            foreach ((array) ($posted[$role['id']] ?? []) as $pid) {
                if (isset($validPerm[(int) $pid])) $want[(int) $pid] = true;
            }
            $have = $granted[$role['id']] ?? [];
            $w = array_keys($want);
            $h = array_keys($have);
            sort($w);
            sort($h);
            if ($w === $h) continue;
            db_query('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
            foreach (array_keys($want) as $pid) db_insert('role_permissions', ['role_id' => $role['id'], 'permission_id' => $pid]);
            $changes[$role['name']] = ['added' => count(array_diff_key($want, $have)), 'removed' => count(array_diff_key($have, $want))];
        }
    });
    if ($changes) {
        audit_log((int) $me['id'], 'updated', 'roles', null, 'Changed permissions: ' . implode(', ', array_map(fn($n, $c) => "$n (+{$c['added']}/-{$c['removed']})", array_keys($changes), $changes)));
    }
    flash_set('success', $changes ? 'Permissions saved.' : 'Nothing changed.');
    redirect(url('admin/roles'));
}

$byModule = [];
foreach ($perms as $p) $byModule[$p['module']][] = $p;
$admin = ['title' => 'Roles & permissions', 'subtitle' => 'What each role can see and do. Super Admin always has full access.', 'active' => 'staff',
          'actions' => '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/staff')) . '"><i class="bi bi-arrow-left"></i> Staff</a>'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Tip: <strong>View all leads</strong> lets a role see every lead; without it, users only see leads assigned to them. Technicians should only have the two <em>Jobs</em> permissions.</div>
<form method="post" class="panel">
  <?= csrf_field() ?>
  <div class="table-responsive perm-matrix">
    <table class="table admin-table mb-0">
      <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th class="text-center"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($byModule as $module => $list): ?>
          <tr class="group-row"><td colspan="<?= count($roles) + 1 ?>"><?= e(ucfirst($module)) ?></td></tr>
          <?php foreach ($list as $p): ?>
            <tr>
              <td><?= e($p['label']) ?><small class="d-block text-muted-2 mono"><?= e($p['slug']) ?></small></td>
              <?php foreach ($roles as $r): $super = $r['slug'] === 'super_admin'; $on = $super || isset($granted[$r['id']][$p['id']]); ?>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="perm[<?= (int) $r['id'] ?>][]" value="<?= (int) $p['id'] ?>"<?= $on ? ' checked' : '' ?><?= $super ? ' disabled' : '' ?> aria-label="<?= e($r['name'] . ': ' . $p['label']) ?>"></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-foot"><span class="small text-muted-2">Changes are recorded in the audit log.</span><button class="btn btn-grad" type="submit">Save permissions</button></div>
</form>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
