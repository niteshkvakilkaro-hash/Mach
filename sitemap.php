<?php
/** /sitemap.xml — generated from active services and pages. */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');

$urls = [
    ['loc' => url(), 'priority' => '1.0', 'changefreq' => 'weekly'],
    ['loc' => url('services'), 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['loc' => url('book-service'), 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => url('about'), 'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => url('contact'), 'priority' => '0.6', 'changefreq' => 'monthly'],
];
foreach (get_services() as $s) {
    $urls[] = ['loc' => url('services/' . $s['slug']), 'priority' => '0.9', 'changefreq' => 'weekly', 'lastmod' => date('Y-m-d', strtotime($s['updated_at']))];
}
foreach (db_all('SELECT slug, updated_at FROM pages WHERE is_active = 1') as $p) {
    $urls[] = ['loc' => url($p['slug']), 'priority' => '0.3', 'changefreq' => 'yearly', 'lastmod' => date('Y-m-d', strtotime($p['updated_at']))];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . e($u['loc']) . '</loc>'
        . (isset($u['lastmod']) ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '')
        . '<changefreq>' . $u['changefreq'] . '</changefreq><priority>' . $u['priority'] . "</priority></url>\n";
}
echo '</urlset>';
