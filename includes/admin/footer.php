<?php /** Admin layout footer. Pages may set $adminScripts (array of extra script URLs). */ ?>
    </main>
  </div>
</div>
<script>
window.ADMIN = <?= js_json(['baseUrl' => rtrim(config('app.url'), '/'), 'csrf' => csrf_token()]) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($adminScripts ?? [] as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
