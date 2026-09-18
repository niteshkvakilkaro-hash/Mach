<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'profile') {
            $name = clean_str($_POST['name'] ?? '', 100);
            $phoneRaw = clean_str($_POST['phone'] ?? '', 20);
            $phone = $phoneRaw === '' ? null : normalize_phone($phoneRaw);
            if (mb_strlen($name) < 2) {
                $errors[] = 'Name is too short.';
            }
            if ($phoneRaw !== '' && $phone === null) {
                $errors[] = 'Enter a valid 10-digit mobile number.';
            }
            if (!$errors) {
                db_query('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, $me['id']]);
                audit_log((int) $me['id'], 'updated', 'users', (int) $me['id'], 'Updated own profile');
                flash_set('success', 'Profile updated.');
                redirect(url('admin/profile'));
            }
        }

        if ($action === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
            if (!password_verify($current, (string) $hash)) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
                $errors[] = 'New password must be at least 10 characters and include letters and numbers.';
            } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
                $errors[] = 'New passwords do not match.';
            } else {
                db_query('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                session_regenerate_id(true);
                $_SESSION['_default_pw'] = false;
                audit_log((int) $me['id'], 'password_changed', 'users', (int) $me['id'], 'Changed own password');
                flash_set('success', 'Password changed.');
                redirect(url('admin/profile'));
            }
        }
    }
}

$details = db_one('SELECT email, phone, last_login_at, last_login_ip, password_changed_at, created_at FROM users WHERE id = ?', [$me['id']]);
$admin = ['title' => 'My profile', 'subtitle' => 'Your account details and password', 'active' => ''];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><h2>Profile</h2></div>
      <form method="post" class="panel-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="mb-3"><label class="form-label" for="pName">Name</label><input class="form-control" id="pName" name="name" value="<?= e($me['name']) ?>" required maxlength="100"></div>
        <div class="mb-3"><label class="form-label" for="pEmail">Email</label><input class="form-control" id="pEmail" value="<?= e($details['email']) ?>" disabled><div class="form-text">Ask a Super Admin to change your login email.</div></div>
        <div class="mb-4"><label class="form-label" for="pPhone">Mobile</label><input class="form-control" id="pPhone" name="phone" value="<?= e($details['phone']) ?>" inputmode="tel"></div>
        <button class="btn btn-grad" type="submit">Save profile</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><h2>Change password</h2></div>
      <form method="post" class="panel-body" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="mb-3"><label class="form-label" for="pCur">Current password</label><input class="form-control" id="pCur" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="mb-3"><label class="form-label" for="pNew">New password</label><input class="form-control" id="pNew" name="new_password" type="password" required minlength="10" autocomplete="new-password"><div class="form-text">At least 10 characters, with letters and numbers.</div></div>
        <div class="mb-4"><label class="form-label" for="pConf">Confirm new password</label><input class="form-control" id="pConf" name="confirm_password" type="password" required autocomplete="new-password"></div>
        <button class="btn btn-grad" type="submit">Update password</button>
      </form>
    </div>
    <div class="panel mt-4">
      <div class="panel-body small text-muted-2">
        <div>Role: <strong class="text-body"><?= e($me['role_name']) ?></strong></div>
        <div>Last sign-in: <?= e($details['last_login_at'] ? date('d M Y, h:i A', strtotime($details['last_login_at'])) . ' from ' . $details['last_login_ip'] : '—') ?></div>
        <div>Password last changed: <?= e($details['password_changed_at'] ? date('d M Y', strtotime($details['password_changed_at'])) : 'never') ?></div>
      </div>
    </div>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
