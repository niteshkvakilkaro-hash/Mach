<?php
if (!defined('ROOT_PATH')) {
    require __DIR__ . '/includes/bootstrap.php';
}
http_response_code(404);
$page = ['title' => 'Page not found', 'noindex' => true, 'canonical' => '404'];
require __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container-xl text-center">
    <p class="eyebrow justify-content-center"><i class="bi bi-signpost-2"></i> Error 404</p>
    <h1 class="display-title">This page needs a repair.</h1>
    <p class="text-muted-2 mb-4">The page you were looking for doesn't exist or has moved.</p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
      <a class="btn btn-grad btn-lg" href="<?= e(url()) ?>">Go home</a>
      <a class="btn btn-ghost btn-lg" href="<?= e(url('services')) ?>">Browse services</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
