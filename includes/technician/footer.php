<?php /** Technician app footer with bottom tab bar. */ ?>
</main>
<nav class="tech-tabs" aria-label="Jobs">
  <?php foreach (['today' => ['bi-sun', 'Today'], 'upcoming' => ['bi-calendar3', 'Upcoming'], 'done' => ['bi-check2-all', 'Done']] as $k => [$icon, $label]): ?>
    <a class="<?= ($appTab ?? '') === $k ? 'is-active' : '' ?>" href="<?= e(url('technician' . ($k === 'today' ? '' : '?view=' . $k))) ?>"<?= ($appTab ?? '') === $k ? ' aria-current="page"' : '' ?>>
      <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<script>
window.ADMIN = <?= js_json(['baseUrl' => rtrim(config('app.url'), '/'), 'csrf' => csrf_token()]) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
