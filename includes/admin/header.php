<?php
/**
 * Admin layout header (sidebar + topbar).
 * Pages set $admin = ['title' => '', 'active' => 'nav key', 'subtitle' => '', 'actions' => html] before including.
 */
$me = auth_user();
$admin = $admin ?? [];
$activeKey = $admin['active'] ?? '';
$businessName = setting('business_name', 'Admin');
$nav = require ROOT_PATH . '/includes/admin/nav.php';

// Overdue follow-up notifications, checked at most every 5 minutes per session (cron can also run it)
if (time() - (int) ($_SESSION['_overdue_check'] ?? 0) > 300) {
    $_SESSION['_overdue_check'] = time();
    followups_notify_overdue();
    complaints_notify_overdue();
}

// Flush any queued email after this admin page is sent (backup for when cron is not set up)
if (mail_ready() && db_value("SELECT 1 FROM email_queue WHERE status = 'pending' AND send_after <= NOW() LIMIT 1")) {
    mail_process_after_response();
}

// Sidebar counters (one cheap query)
$navBadges = [];
if (can('leads.view')) {
    [$scopeSql, $scopeParams] = lead_visibility('l');
    $navBadges['new_leads'] = (int) db_value("SELECT COUNT(*) FROM leads l WHERE l.deleted_at IS NULL AND l.status = 'new' $scopeSql", $scopeParams);
}

if (can('complaints.view')) {
    $navBadges['open_complaints'] = complaint_open_count()['open_total'];
}

// Drop items the user cannot see, and sections left empty
$visible = [];
foreach ($nav as $item) {
    if (isset($item['section']) || can($item['perm'])) {
        $visible[] = $item;
    }
}
$visible = array_values(array_filter($visible, function ($item, $i) use ($visible) {
    if (!isset($item['section'])) {
        return true;
    }
    $next = $visible[$i + 1] ?? null;
    return $next && !isset($next['section']);
}, ARRAY_FILTER_USE_BOTH));

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($admin['title'] ?? 'Admin') . ' · ' . $businessName) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/admin.css')) ?>" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-shell">

  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="adminSidebar" aria-label="Admin navigation">
    <div class="sidebar-head">
      <a class="brand" href="<?= e(url('admin/dashboard')) ?>">
        <span class="brand-mark"><i class="bi bi-tools"></i></span>
        <span class="brand-text"><span class="brand-name"><?= e(strtok($businessName, ' ')) ?></span><span class="brand-sub">CRM</span></span>
      </a>
      <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close"></button>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($visible as $item): ?>
        <?php if (isset($item['section'])): ?>
          <div class="nav-section"><?= e($item['section']) ?></div>
          <?php continue; endif; ?>
        <?php
          $isOpen = $activeKey === $item['key'] || str_starts_with($activeKey, $item['key'] . '.');
          $badge = isset($item['badge']) ? ($navBadges[$item['badge']] ?? 0) : 0;
        ?>
        <?php if (!$item['ready']): ?>
          <span class="nav-item is-soon" aria-disabled="true" title="Coming in a later phase">
            <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span><em>Soon</em>
          </span>
        <?php elseif (!empty($item['children'])): ?>
          <button class="nav-item<?= $isOpen ? ' is-active' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#nav-<?= e($item['key']) ?>" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
            <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
            <?php if ($badge): ?><b class="nav-badge"><?= $badge ?></b><?php endif; ?>
            <i class="bi bi-chevron-down chev"></i>
          </button>
          <div class="collapse<?= $isOpen ? ' show' : '' ?>" id="nav-<?= e($item['key']) ?>">
            <div class="nav-sub">
              <?php foreach ($item['children'] as $child): ?>
                <a class="<?= $activeKey === $child['key'] ? 'is-active' : '' ?>" href="<?= e(url($child['url'])) ?>"><?= e($child['label']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <a class="nav-item<?= $isOpen ? ' is-active' : '' ?>" href="<?= e(url($item['url'])) ?>"<?= $isOpen ? ' aria-current="page"' : '' ?>>
            <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
            <?php if ($badge): ?><b class="nav-badge"><?= $badge ?></b><?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a class="nav-item" href="<?= e(url()) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span>View website</span></a>
    </div>
  </aside>

  <div class="admin-main">
    <header class="topbar">
      <button class="icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>

      <?php if (can('leads.view') && is_file(ROOT_PATH . '/admin/leads/index.php')): ?>
      <form class="top-search d-none d-md-flex" action="<?= e(url('admin/leads')) ?>" method="get" role="search">
        <i class="bi bi-search"></i>
        <input name="q" type="search" placeholder="Search leads by name, phone, lead no. or email" aria-label="Search leads">
        <kbd>/</kbd>
      </form>
      <?php endif; ?>

      <div class="ms-auto d-flex align-items-center gap-2">
        <div class="dropdown">
          <button class="icon-btn position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications" id="notifBtn">
            <i class="bi bi-bell"></i><span class="notif-dot d-none" id="notifCount"></span>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-menu" aria-labelledby="notifBtn">
            <div class="notif-head">
              <strong>Notifications</strong>
              <button class="btn btn-link btn-sm p-0" type="button" id="notifReadAll">Mark all read</button>
            </div>
            <div class="notif-list" id="notifList"><div class="notif-empty">Loading…</div></div>
          </div>
        </div>

        <div class="dropdown">
          <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= e(initials($me['name'])) ?></span>
            <span class="d-none d-sm-flex flex-column text-start lh-sm">
              <strong><?= e($me['name']) ?></strong><small><?= e($me['role_name']) ?></small>
            </span>
            <i class="bi bi-chevron-down small d-none d-sm-inline"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= e(url('admin/profile')) ?>"><i class="bi bi-person-circle me-2"></i>My profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form action="<?= e(url('admin/logout')) ?>" method="post">
                <?= csrf_field() ?>
                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <main class="admin-content" id="main">
      <div class="page-head">
        <div>
          <h1><?= e($admin['title'] ?? '') ?></h1>
          <?php if (!empty($admin['subtitle'])): ?><p><?= e($admin['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($admin['actions'])): ?><div class="page-actions"><?= $admin['actions'] ?></div><?php endif; ?>
      </div>
      <?php
      // Warn until the seeded default password is changed (checked once per session; bcrypt is slow on purpose)
      if (!isset($_SESSION['_default_pw'])) {
          $_SESSION['_default_pw'] = password_verify('Admin@12345', (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$me['id']]));
      }
      if ($_SESSION['_default_pw']): ?>
        <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2"><i class="bi bi-shield-exclamation"></i>
          <strong>You are using the default password.</strong> Anyone who has seen the setup guide can sign in as you.
          <a class="btn btn-sm btn-danger ms-auto" href="<?= e(url('admin/profile')) ?>">Change password now</a></div>
      <?php endif; ?>
      <?php if ($msg = flash_get('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash_get('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
