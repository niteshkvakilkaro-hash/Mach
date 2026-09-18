<?php
/** CMS pages: /privacy-policy, /terms, /refund-policy (content managed in the `pages` table). */
require __DIR__ . '/includes/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$cms = $slug !== '' ? get_page($slug) : null;
if (!$cms) {
    require __DIR__ . '/404.php';
    exit;
}

$page = [
    'title'       => $cms['seo_title'] ?: $cms['title'],
    'description' => $cms['seo_description'] ?: $cms['title'] . ' — ' . setting('business_name'),
    'canonical'   => $cms['slug'],
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container-xl">
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url()) ?>">Home</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($cms['title']) ?></li></ol></nav>
    <h1><?= e($cms['title']) ?></h1>
    <p class="text-muted-2">Last updated <?= e(date('d M Y', strtotime($cms['updated_at']))) ?></p>
  </div>
</section>
<section class="section pt-0">
  <div class="container-xl">
    <div class="glass-card p-4 p-md-5 prose" style="max-width:860px"><?= render_text($cms['content']) ?></div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
