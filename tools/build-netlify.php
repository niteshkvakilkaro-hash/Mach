<?php
/**
 * Builds a static design preview of the public website for Netlify.
 *   php tools/build-netlify.php
 * Output: netlify-preview/ (folder) and netlify-preview.zip — drag either onto https://app.netlify.com/drop
 *
 * What works in the preview: every public page, design, mobile layout, links, WhatsApp/call buttons.
 * Forms are sent to Netlify Forms (visible in the Netlify dashboard). PHP, MySQL, CRM and admin do NOT run on Netlify.
 * Requires the site to be running locally (XAMPP) at config('app.url').
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/includes/bootstrap.php';

$base = rtrim(config('app.url'), '/');
$out = ROOT_PATH . '/netlify-preview';

// ---------------------------------------------------------------- pages to export
$pages = ['' => 'index.html', 'about' => 'about/index.html', 'services' => 'services/index.html',
          'book-service' => 'book-service/index.html', 'contact' => 'contact/index.html', 'thank-you' => 'thank-you/index.html',
          'privacy-policy' => 'privacy-policy/index.html', 'terms' => 'terms/index.html', 'refund-policy' => 'refund-policy/index.html',
          'this-page-does-not-exist' => '404.html'];
foreach (get_services() as $s) {
    $pages['services/' . $s['slug']] = 'services/' . $s['slug'] . '/index.html';
}

// ---------------------------------------------------------------- clean output
$rrmdir = function (string $dir) use (&$rrmdir) {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        is_dir("$dir/$f") ? $rrmdir("$dir/$f") : unlink("$dir/$f");
    }
    rmdir($dir);
};
$rrmdir($out);
mkdir($out, 0775, true);

$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30, 'header' => "User-Agent: netlify-preview-builder\r\n"]]);

/** Turn a locally rendered page into a static Netlify page. */
$transform = function (string $html) use ($base): string {
    // Absolute local URLs → root-relative (also the JSON-escaped form used in window.APP)
    $html = str_replace([$base . '/', $base, str_replace('/', '\/', $base) . '\/', str_replace('/', '\/', $base)], ['/', '/', '\/', ''], $html);

    // Session CSRF tokens are meaningless in a static file
    $html = preg_replace('#\s*<input type="hidden" name="_csrf" value="[^"]*">#', '', $html);

    // Lead forms → Netlify Forms (name = form type, honeypot = our existing "website" field)
    $html = preg_replace_callback(
        '#<form([^>]*?)\saction="[^"]*"([^>]*data-lead-form[^>]*)>(\s*)<input type="hidden" name="form_type" value="(\w+)">#',
        fn($m) => '<form' . $m[1] . ' action="/thank-you/" name="' . $m[4] . '" data-netlify="true" netlify-honeypot="website"' . $m[2] . '>'
            . $m[3] . '<input type="hidden" name="form-name" value="' . $m[4] . '"><input type="hidden" name="form_type" value="' . $m[4] . '">',
        $html
    );

    // Preview must not be indexed by search engines
    $html = str_replace('<meta name="theme-color"', '<meta name="robots" content="noindex, nofollow">' . "\n" . '<meta name="theme-color"', $html);

    // Netlify form handler loads before site.js so it takes over form submission
    $html = str_replace('<script src="https://cdn.jsdelivr.net/npm/bootstrap@', '<script src="/assets/js/netlify-forms.js"></script>' . "\n" . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@', $html);
    return $html;
};

$failed = 0;
foreach ($pages as $path => $file) {
    $html = @file_get_contents($base . '/' . $path, false, $ctx);
    $status = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;
    $ok = $html !== false && ($status === 200 || ($file === '404.html' && $status === 404));
    if (!$ok) {
        $failed++;
        echo "FAILED  /$path (HTTP $status)\n";
        continue;
    }
    $target = "$out/$file";
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    file_put_contents($target, $transform($html));
    echo "ok      /$path\n";
}

// ---------------------------------------------------------------- assets
$copy = function (string $src, string $dst) use (&$copy) {
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0775, true);
        foreach (array_diff(scandir($src), ['.', '..']) as $f) $copy("$src/$f", "$dst/$f");
    } else {
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true);
        copy($src, $dst);
    }
};
$copy(ROOT_PATH . '/assets/css/site.css', "$out/assets/css/site.css");
$copy(ROOT_PATH . '/assets/js/site.js', "$out/assets/js/site.js");
$copy(ROOT_PATH . '/assets/images', "$out/assets/images");

file_put_contents("$out/assets/js/netlify-forms.js", <<<'JS'
/* Netlify preview only: sends lead forms to Netlify Forms instead of the PHP API. */
document.addEventListener('submit', async function (ev) {
  var form = ev.target.closest && ev.target.closest('form[data-netlify]');
  if (!form) return;
  ev.preventDefault();
  ev.stopImmediatePropagation();
  if (!form.checkValidity()) {
    form.classList.add('was-validated');
    var bad = form.querySelector(':invalid');
    if (bad) bad.focus();
    return;
  }
  var btn = form.querySelector('[type=submit]');
  if (btn) { btn.disabled = true; btn.dataset.label = btn.innerHTML; btn.textContent = 'Sending…'; }
  try {
    var res = await fetch('/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(new FormData(form)).toString()
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    try { sessionStorage.setItem('mach_cb_shown', '1'); } catch (e) {}
    window.location.href = '/thank-you/';
  } catch (e) {
    alert('Could not send right now. Please call or WhatsApp us.');
    if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.label; }
  }
}, true);
JS);

file_put_contents("$out/_headers", "/*\n  X-Robots-Tag: noindex, nofollow\n  X-Content-Type-Options: nosniff\n  X-Frame-Options: SAMEORIGIN\n  Referrer-Policy: strict-origin-when-cross-origin\n");
file_put_contents("$out/robots.txt", "User-agent: *\nDisallow: /\n");

// ---------------------------------------------------------------- zip
$zipPath = ROOT_PATH . '/netlify-preview.zip';
if (class_exists('ZipArchive')) {
    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($out) + 1)));
    }
    $zip->close();
    echo "\nZip: $zipPath\n";
}
echo "Folder: $out\n" . ($failed ? "$failed page(s) failed.\n" : "All pages exported.\n");
exit($failed ? 1 : 0);
