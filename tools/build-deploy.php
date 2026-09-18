<?php
/**
 * Builds deploy/mach-live.zip (files to upload to the hosting) and deploy/mach-database.sql (database export).
 *   php tools/build-deploy.php
 * The zip contains only what the live site needs — including the hidden .htaccess security files —
 * and leaves out docs, tools, the database folder, the Netlify preview, logs and test uploads.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$root = dirname(__DIR__);
$outDir = $root . '/deploy';
if (!is_dir($outDir)) mkdir($outDir, 0775, true);
file_put_contents("$outDir/.htaccess", "Require all denied\n");

// every public page in the root (feedback.php, complaint.php, …) — files starting with _ are local helpers
$rootPages = array_map('basename', array_filter(glob("$root/*.php"), fn($p) => basename($p)[0] !== '_'));
$include = ['.htaccess', ...$rootPages,
            'admin', 'api', 'assets', 'config', 'cron', 'includes', 'technician', 'uploads', 'storage'];
$skip = fn(string $rel) => preg_match('#^storage/logs/.+\.log$#', $rel)          // local error logs
    || preg_match('#^uploads/[^/]+/[^/]+\.(jpg|png|webp)$#', $rel) && getenv('WITH_UPLOADS') !== '1'; // test images (logo etc. are re-uploaded in Settings)

$zipPath = "$outDir/mach-live.zip";
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$count = 0;
foreach ($include as $item) {
    $path = "$root/$item";
    if (is_file($path)) {
        $zip->addFile($path, $item);
        $count++;
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($f->isDir()) { $zip->addEmptyDir($rel); continue; }
        if ($skip($rel)) continue;
        $zip->addFile($f->getPathname(), $rel);
        $count++;
    }
}
$zip->close();
echo "Files zip:    deploy/mach-live.zip ($count files, " . round(filesize($zipPath) / 1024) . " KB)\n";

// Database export (no CREATE DATABASE / USE, so it imports into any database name on the host)
$cfg = require "$root/config/database.php";
$dump = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump';
$sqlPath = "$outDir/mach-database.sql";
$cmd = sprintf('"%s" -h%s -P%d -u%s %s --default-character-set=utf8mb4 --single-transaction --skip-comments %s > "%s"',
    $dump, $cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'] !== '' ? '-p' . escapeshellarg($cfg['pass']) : '', $cfg['name'], $sqlPath);
exec($cmd, $o, $code);
if ($code !== 0 || !filesize($sqlPath)) {
    echo "Database export FAILED — export it from phpMyAdmin instead (see docs/DEPLOYMENT.md).\n";
    exit(1);
}
echo "Database:     deploy/mach-database.sql (" . round(filesize($sqlPath) / 1024) . " KB)\n";
echo "Next: follow docs/DEPLOYMENT.md from step 3.\n";
