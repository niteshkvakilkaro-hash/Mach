<?php
/**
 * Technician app layout (mobile-first). Pages set $tech (technician row), $appTitle, $appTab ('today'|'upcoming'|'done'|''), $backUrl.
 */
$me = auth_user();
$businessName = setting('business_name', 'Jobs');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($appTitle ?? 'My jobs') . ' · ' . $businessName) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#07051A">
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/admin.css')) ?>" rel="stylesheet">
<link href="<?= e(asset('css/tech.css')) ?>" rel="stylesheet">
</head>
<body class="tech-body">
<header class="tech-top">
  <?php if (!empty($backUrl)): ?>
    <a class="icon-btn" href="<?= e($backUrl) ?>" aria-label="Back"><i class="bi bi-arrow-left"></i></a>
  <?php else: ?>
    <span class="brand-mark"><i class="bi bi-tools"></i></span>
  <?php endif; ?>
  <div class="tech-top-title">
    <strong><?= e($appTitle ?? 'My jobs') ?></strong>
    <small><?= e($tech['name'] ?? $me['name']) ?></small>
  </div>
  <div class="dropdown">
    <button class="icon-btn position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications" id="notifBtn">
      <i class="bi bi-bell"></i><span class="notif-dot d-none" id="notifCount"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end notif-menu" aria-labelledby="notifBtn">
      <div class="notif-head"><strong>Notifications</strong><button class="btn btn-link btn-sm p-0" type="button" id="notifReadAll">Mark all read</button></div>
      <div class="notif-list" id="notifList"><div class="notif-empty">Loading…</div></div>
    </div>
  </div>
  <form action="<?= e(url('admin/logout')) ?>" method="post">
    <?= csrf_field() ?>
    <button class="icon-btn" type="submit" aria-label="Sign out" title="Sign out"><i class="bi bi-box-arrow-right"></i></button>
  </form>
</header>
<main class="tech-main" id="main">
<?php if ($msg = flash_get('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash_get('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
