<?php
/**
 * Staff accounts (office users). Technicians' logins are managed on the Technicians page.
 * Safety rules: you can't lock yourself out, the last Super Admin can't be removed,
 * and only a Super Admin can create or edit Super Admins.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_permission('staff.view');
$isSuper = $me['role_slug'] === 'super_admin';

$roles = db_all("SELECT id, name, slug, description FROM roles WHERE slug <> 'technician' ORDER BY id");
$assignableRoles = array_filter($roles, fn($r) => $isSuper || $r['slug'] !== 'super_admin');
$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $id ? db_one("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.deleted_at IS NULL AND r.slug <> 'technician'", [$id]) : null;
$activeSupers = fn() => (int) db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL");
$errors = [];

/** Return an error message if $me may not change $target this way, else null. */
$guard = function (?array $target, string $op, ?int $newRoleId = null, ?string $newStatus = null) use ($me, $isSuper, $activeSupers, $roles) {
    if ($target && $target['role_slug'] === 'super_admin' && !$isSuper) return 'Only a Super Admin can change a Super Admin account.';
    $newRole = $newRoleId ? (array_column($roles, 'slug', 'id')[$newRoleId] ?? null) : null;
    if ($newRole === 'super_admin' && !$isSuper) return 'Only a Super Admin can give the Super Admin role.';
    if ($target && (int) $target['id'] === (int) $me['id']) {
        if ($op === 'delete') return 'You cannot delete your own account.';
        if ($newStatus === 'inactive') return 'You cannot deactivate your own account.';
        if ($newRoleId && $newRoleId !== (int) $target['role_id']) return 'You cannot change your own role.';
    }
    $losesSuper = $target && $target['role_slug'] === 'super_admin' && $target['status'] === 'active'
        && ($op === 'delete' || $newStatus === 'inactive' || ($newRole && $newRole !== 'super_admin'));
    if ($losesSuper && $activeSupers() <= 1) return 'At least one active Super Admin is required.';
    return null;
};

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect(url('admin/staff'));
    }
    if (in_array($op, ['delete', 'toggle'], true)) {
        require_permission($op === 'delete' ? 'staff.delete' : 'staff.edit');
        if (!$user) { flash_set('error', 'User not found.'); redirect(url('admin/staff')); }
        $newStatus = $op === 'toggle' ? ($user['status'] === 'active' ? 'inactive' : 'active') : null;
        if ($err = $guard($user, $op, null, $newStatus)) { flash_set('error', $err); redirect(url('admin/staff')); }
        if ($op === 'delete') {
            db_query("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ?", [$user['id']]);
            audit_log((int) $me['id'], 'deleted', 'users', (int) $user['id'], "Deleted staff account {$user['email']}");
            $open = (int) db_value('SELECT COUNT(*) FROM leads l JOIN lead_statuses s ON s.slug = l.status WHERE l.assigned_to = ? AND s.is_closed = 0 AND l.deleted_at IS NULL', [$user['id']]);
            flash_set('success', "{$user['name']} deleted." . ($open ? " $open open lead(s) are still assigned to them — reassign from Leads (filter: Assigned staff)." : ''));
        } else {
            db_query('UPDATE users SET status = ? WHERE id = ?', [$newStatus, $user['id']]);
            audit_log((int) $me['id'], 'status_changed', 'users', (int) $user['id'], "{$user['email']} → $newStatus");
            flash_set('success', "{$user['name']} is now " . ($newStatus === 'active' ? 'active.' : 'deactivated and signed out.'));
        }
        redirect(url('admin/staff'));
    }
    if ($op === 'save') {
        require_permission($user ? 'staff.edit' : 'staff.create');
        $name = clean_str($_POST['name'] ?? '', 100);
        $email = strtolower(clean_str($_POST['email'] ?? '', 150));
        $phoneRaw = clean_str($_POST['phone'] ?? '', 20);
        $phone = $phoneRaw === '' ? null : normalize_phone($phoneRaw);
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($name) < 2) $errors['name'] = 'Enter the name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
        elseif (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id'] ?? 0])) $errors['email'] = 'Another account (possibly deleted or a technician) already uses this email.';
        if ($phoneRaw !== '' && $phone === null) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
        if (!in_array($roleId, array_map('intval', array_column($assignableRoles, 'id')), true)) $errors['role_id'] = 'Choose a role.';
        if ((!$user || $password !== '') && (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) {
            $errors['password'] = 'At least 10 characters with letters and numbers.';
        }
        if (!$errors && ($err = $guard($user, 'save', $roleId, $status))) $errors['_'] = $err;

        if (!$errors) {
            $data = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role_id' => $roleId, 'status' => $status];
            if ($password !== '') {
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $data['password_changed_at'] = date('Y-m-d H:i:s');
            }
            if ($user) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                db_query("UPDATE users SET $sets WHERE id = ?", array_merge(array_values($data), [$user['id']]));
                $logged = array_diff_key($data, ['password_hash' => 1]);
                audit_log((int) $me['id'], 'updated', 'users', (int) $user['id'], "Edited staff {$email}" . ($password !== '' ? ' (password reset)' : ''), array_intersect_key($user, $logged), $logged);
            } else {
                $newId = db_insert('users', $data);
                audit_log((int) $me['id'], 'created', 'users', $newId, "Created staff account {$email}");
            }
            flash_set('success', $user ? 'Account updated.' : "Account created. Share the login email and password with $name securely.");
            redirect(url('admin/staff'));
        }
        $action = $user ? 'edit' : 'new';
    }
}

// ---------------------------------------------------------------- form
if ($action === 'edit' || $action === 'new') {
    require_permission($user ? 'staff.edit' : 'staff.create');
    $v = array_merge(['name' => '', 'email' => '', 'phone' => '', 'role_id' => '', 'status' => 'active'], $user ?: [], array_map(fn($x) => is_string($x) ? $x : '', $_POST));
    $admin = ['title' => $user ? 'Edit ' . $user['name'] : 'New staff account', 'subtitle' => $user ? $user['email'] : 'For office staff. Add technicians from the Technicians page.', 'active' => 'staff'];
    require ROOT_PATH . '/includes/admin/header.php';
    $fm = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $fc = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
    ?>
    <?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>
    <form method="post" class="panel" style="max-width:860px" novalidate autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="op" value="save"><?php if ($user): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>
      <div class="panel-body row g-3">
        <div class="col-md-6"><label class="form-label" for="sName">Name *</label><input class="form-control<?= $fc('name') ?>" id="sName" name="name" value="<?= e($v['name']) ?>" maxlength="100" required><?= $fm('name') ?></div>
        <div class="col-md-6"><label class="form-label" for="sPhone">Mobile</label><input class="form-control<?= $fc('phone') ?>" id="sPhone" name="phone" value="<?= e($v['phone']) ?>" inputmode="tel"><?= $fm('phone') ?></div>
        <div class="col-md-6"><label class="form-label" for="sEmail">Login email *</label><input class="form-control<?= $fc('email') ?>" id="sEmail" name="email" type="email" value="<?= e($v['email']) ?>" maxlength="150" required><?= $fm('email') ?></div>
        <div class="col-md-6"><label class="form-label" for="sPass"><?= $user ? 'New password (leave blank to keep)' : 'Password *' ?></label><input class="form-control<?= $fc('password') ?>" id="sPass" name="password" type="password" autocomplete="new-password" minlength="10"><?= $fm('password') ?><div class="form-text">At least 10 characters with letters and numbers.</div></div>
        <div class="col-12">
          <label class="form-label">Role *</label>
          <div class="role-pick">
            <?php foreach ($assignableRoles as $r): ?>
              <label class="role-option"><input type="radio" name="role_id" value="<?= (int) $r['id'] ?>"<?= (string) $v['role_id'] === (string) $r['id'] ? ' checked' : '' ?>><span><strong><?= e($r['name']) ?></strong><small><?= e($r['description']) ?></small></span></label>
            <?php endforeach; ?>
          </div><?= $fm('role_id') ?>
        </div>
        <div class="col-md-6"><label class="form-label" for="sStatus">Account status</label>
          <select class="form-select" id="sStatus" name="status"><?= select_options(['active' => 'Active — can sign in', 'inactive' => 'Inactive — blocked'], $v['status'] ?? 'active') ?></select></div>
      </div>
      <div class="panel-foot"><a class="btn btn-ghost" href="<?= e(url('admin/staff')) ?>">Cancel</a><button class="btn btn-grad" type="submit"><?= $user ? 'Save changes' : 'Create account' ?></button></div>
    </form>
    <?php
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$rows = db_all(
    "SELECT u.*, r.name AS role_name, r.slug AS role_slug,
            (SELECT COUNT(*) FROM leads l JOIN lead_statuses s ON s.slug = l.status WHERE l.assigned_to = u.id AND s.is_closed = 0 AND l.deleted_at IS NULL) AS open_leads
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.deleted_at IS NULL AND r.slug <> 'technician' ORDER BY u.status, r.id, u.name"
);
$actions = can('roles.manage') ? '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/roles')) . '"><i class="bi bi-shield-lock"></i> Roles &amp; permissions</a>' : '';
if (can('staff.create')) $actions .= '<a class="btn btn-grad btn-sm" href="' . e(url('admin/staff?action=new')) . '"><i class="bi bi-plus-lg"></i> Add staff</a>';
$admin = ['title' => 'Staff', 'subtitle' => count($rows) . ' office accounts · technicians are managed under Technicians', 'active' => 'staff', 'actions' => $actions];
require ROOT_PATH . '/includes/admin/header.php';
$mini = fn(array $u, string $op, string $inner, string $cls, string $confirm) => '<form method="post" class="d-inline" data-confirm="' . e($confirm) . '">' . csrf_field()
    . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><button class="' . $cls . '" type="submit">' . $inner . '</button></form>';
?>
<section class="panel">
  <div class="table-responsive">
    <table class="table admin-table mb-0">
      <thead><tr><th>Name</th><th>Role</th><th>Open leads</th><th>Last sign-in</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $u): $self = (int) $u['id'] === (int) $me['id']; $locked = $u['role_slug'] === 'super_admin' && !$isSuper; ?>
          <tr>
            <td><span class="avatar avatar-sm"><?= e(initials($u['name'])) ?></span> <strong><?= e($u['name']) ?></strong><?= $self ? ' <span class="tag">You</span>' : '' ?><small class="d-block text-muted-2 ms-5 ps-1"><?= e($u['email']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></small></td>
            <td><?= e($u['role_name']) ?></td>
            <td><?= (int) $u['open_leads'] ? '<a href="' . e(url('admin/leads?status=open&assigned=' . $u['id'])) . '">' . (int) $u['open_leads'] . '</a>' : '0' ?></td>
            <td class="text-muted-2"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : 'Never' ?></td>
            <td><?= status_badge($u['status'] === 'active' ? 'Active' : 'Inactive', $u['status'] === 'active' ? 'success' : 'secondary') ?></td>
            <td class="text-end text-nowrap">
              <?php if (!$locked): ?>
                <?php if (can('staff.edit')): ?><a class="icon-btn icon-btn-sm" href="<?= e(url('admin/staff?action=edit&id=' . $u['id'])) ?>" aria-label="Edit <?= e($u['name']) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
                <?php if (can('staff.edit') && !$self): ?><?= $mini($u, 'toggle', $u['status'] === 'active' ? '<i class="bi bi-pause-circle"></i><span class="visually-hidden">Deactivate</span>' : '<i class="bi bi-play-circle"></i><span class="visually-hidden">Activate</span>', 'icon-btn icon-btn-sm', $u['status'] === 'active' ? "Deactivate {$u['name']}? They will be signed out immediately." : "Activate {$u['name']}?") ?><?php endif; ?>
                <?php if (can('staff.delete') && !$self): ?><?= $mini($u, 'delete', '<i class="bi bi-trash"></i><span class="visually-hidden">Delete</span>', 'icon-btn icon-btn-sm text-danger', "Delete {$u['name']}'s account? Their history stays in the audit log.") ?><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
