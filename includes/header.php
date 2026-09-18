<?php
/**
 * Public layout header.
 * Pages set $page before including:
 *   title, description, canonical (path), og_image, schema (array of JSON-LD), noindex, body_class, no_exit_popup
 */
$page = $page ?? [];
$businessName = setting('business_name', 'Home Services');
$metaTitle = !empty($page['title']) ? $page['title'] . ' | ' . $businessName : $businessName . ' — ' . setting('seo_default_title');
$metaDesc = $page['description'] ?? setting('seo_default_description');
$canonical = url($page['canonical'] ?? ltrim(current_path(), '/'));
$ogImage = $page['og_image'] ?? (setting('og_image') ? url(setting('og_image')) : '');
$path = current_path();
$navCategories = get_categories_with_services();

$navLinks = [
    ['AC Repair', 'services/ac-repair'],
    ['Chimney', 'services/chimney-repair'],
    ['Geyser', 'services/geyser-repair'],
    ['Washing Machine', 'services/washing-machine-repair'],
    ['Refrigerator', 'services/refrigerator-repair'],
];
$isActive = fn(string $p) => $path === '/' . trim($p, '/') ? ' active' : '';
?><!doctype html>
<html lang="en-IN" data-bs-theme="dark" class="no-js">
<head>
<meta charset="utf-8">
<script>document.documentElement.classList.remove('no-js')</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($metaTitle) ?></title>
<meta name="description" content="<?= e($metaDesc) ?>">
<?php if (!empty($page['keywords'])): ?><meta name="keywords" content="<?= e($page['keywords']) ?>"><?php endif; ?>
<?php if (!empty($page['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#07051A">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($businessName) ?>">
<meta property="og:title" content="<?= e($metaTitle) ?>">
<meta property="og:description" content="<?= e($metaDesc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta property="og:locale" content="en_IN">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" href="<?= e(setting('favicon') ? url(setting('favicon')) : asset('images/favicon.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/site.css')) ?>" rel="stylesheet">
<?php foreach (array_filter($page['schema'] ?? []) as $schema): ?>
<script type="application/ld+json"><?= js_json($schema) ?></script>
<?php endforeach; ?>
</head>
<body class="<?= e($page['body_class'] ?? '') ?>"<?= !empty($page['no_exit_popup']) ? ' data-no-exit' : '' ?>>
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

<header class="site-header" id="siteHeader">
  <nav class="container-xl d-flex align-items-center gap-3 py-3" aria-label="Main">
    <a class="brand" href="<?= e(url()) ?>" aria-label="<?= e($businessName) ?> home">
      <?php if (setting('logo')): ?>
        <img src="<?= e(url(setting('logo'))) ?>" alt="<?= e($businessName) ?>" height="40">
      <?php else: ?>
        <span class="brand-mark"><i class="bi bi-tools"></i></span>
        <span class="brand-text">
          <span class="brand-name"><?= e(strtok($businessName, ' ')) ?></span>
          <span class="brand-sub"><?= e(setting('business_tagline', 'Home Services')) ?></span>
        </span>
      <?php endif; ?>
    </a>

    <ul class="nav-links d-none d-xl-flex">
      <li><a class="nav-link-item<?= $isActive('/') ?>" href="<?= e(url()) ?>">Home</a></li>
      <li class="dropdown">
        <a class="nav-link-item dropdown-toggle<?= $isActive('services') ?>"
           href="<?= e(url('services')) ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">Services</a>
        <div class="dropdown-menu mega-menu">
          <div class="mega-grid">
            <?php foreach ($navCategories as $cat): ?>
              <div class="mega-col">
                <div class="mega-title"><i class="bi <?= e($cat['icon']) ?>"></i><?= e($cat['name']) ?></div>
                <?php foreach (array_slice($cat['services'], 0, 4) as $s): ?>
                  <a href="<?= e(url('services/' . $s['slug'])) ?>"><?= e($s['name']) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <a class="mega-all" href="<?= e(url('services')) ?>">View all services <i class="bi bi-arrow-right"></i></a>
        </div>
      </li>
      <?php foreach ($navLinks as [$label, $href]): ?>
        <li><a class="nav-link-item<?= $isActive($href) ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
      <?php endforeach; ?>
      <li><a class="nav-link-item<?= $isActive('about') ?>" href="<?= e(url('about')) ?>">About</a></li>
      <li><a class="nav-link-item<?= $isActive('contact') ?>" href="<?= e(url('contact')) ?>">Contact</a></li>
    </ul>

    <div class="ms-auto d-flex align-items-center gap-2">
      <a class="icon-btn d-none d-sm-inline-flex" href="<?= e(tel_link()) ?>" aria-label="Call <?= e(setting('phone')) ?>"><i class="bi bi-telephone"></i></a>
      <a class="icon-btn icon-btn-wa d-none d-sm-inline-flex" href="<?= e(whatsapp_link('Hi, I need appliance repair service.')) ?>" target="_blank" rel="noopener" aria-label="WhatsApp us"><i class="bi bi-whatsapp"></i></a>
      <a class="btn btn-grad d-none d-md-inline-flex" href="<?= e(url('book-service')) ?>">Book Service <i class="bi bi-arrow-right"></i></a>
      <button class="icon-btn d-xl-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open menu"><i class="bi bi-list"></i></button>
    </div>
  </nav>
</header>

<div class="offcanvas offcanvas-end mobile-nav" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
  <div class="offcanvas-header">
    <span class="brand-name" id="mobileNavLabel"><?= e($businessName) ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column">
    <a href="<?= e(url()) ?>">Home</a>
    <a href="<?= e(url('services')) ?>">All Services</a>
    <?php foreach ($navLinks as [$label, $href]): ?>
      <a href="<?= e(url($href)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('about')) ?>">About</a>
    <a href="<?= e(url('contact')) ?>">Contact</a>
    <div class="mt-auto d-grid gap-2 pt-4">
      <a class="btn btn-grad btn-lg" href="<?= e(url('book-service')) ?>">Book Service</a>
      <a class="btn btn-ghost btn-lg" href="<?= e(tel_link()) ?>"><i class="bi bi-telephone"></i> <?= e(setting('phone')) ?></a>
    </div>
  </div>
</div>

<main id="main">
