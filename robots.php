<?php
/** /robots.txt — dynamic so the sitemap URL always matches the configured domain. */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
$prefix = base_path();
echo "User-agent: *\n";
echo "Disallow: {$prefix}/admin/\n";
echo "Disallow: {$prefix}/api/\n";
echo "Disallow: {$prefix}/thank-you\n";
echo "Disallow: {$prefix}/feedback\n";
echo "Disallow: {$prefix}/technician/\n";
echo "Allow: {$prefix}/\n\n";
echo 'Sitemap: ' . url('sitemap.xml') . "\n";
