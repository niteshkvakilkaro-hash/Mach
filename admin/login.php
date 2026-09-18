<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (auth_user()) {
    redirect(safe_admin_redirect($_GET['next'] ?? null));
}

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean_str($_POST['email'] ?? '', 150);
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || ($_POST['password'] ?? '') === '') {
        $error = 'Enter your email and password.';
    } else {
        $error = auth_attempt($email, (string) $_POST['password']);
        if ($error === null) {
            redirect(safe_admin_redirect($_POST['next'] ?? null));
        }
    }
}
$notice = flash_get('login_notice');
$businessName = setting('business_name', 'Admin');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e($businessName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/admin.css')) ?>" rel="stylesheet">
</head>
<body class="login-body">
<main class="login-wrap">
  <div class="login-card">
    <div class="login-glow" aria-hidden="true"></div>
    <a class="brand justify-content-center mb-4" href="<?= e(url()) ?>">
      <span class="brand-mark"><i class="bi bi-tools"></i></span>
      <span class="brand-text"><span class="brand-name"><?= e(strtok($businessName, ' ')) ?></span><span class="brand-sub">CRM</span></span>
    </a>
    <h1 class="h3 text-center mb-1">Welcome back</h1>
    <p class="text-center text-muted-2 mb-4">Sign in to manage leads, bookings and technicians.</p>

    <?php if ($notice): ?><div class="alert alert-info small"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger small" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="<?= e(url('admin/login')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($_GET['next'] ?? $_POST['next'] ?? '') ?>">
      <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input class="form-control form-control-lg" id="email" name="email" type="email" value="<?= e($email) ?>" required autocomplete="username" autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <input class="form-control form-control-lg" id="password" name="password" type="password" required autocomplete="current-password">
          <button class="btn btn-ghost" type="button" data-toggle-password="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button class="btn btn-grad btn-lg w-100" type="submit">Sign in <i class="bi bi-arrow-right"></i></button>
    </form>
    <p class="text-center small text-muted-2 mt-4 mb-0"><i class="bi bi-shield-lock"></i> Protected area. All access is logged.</p>
  </div>
</main>
<script>
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.querySelector(btn.dataset.togglePassword);
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});
</script>
</body>
</html>
