<?php
/**
 * Application configuration.
 * On the live server set 'env' => 'production' and 'url' => 'https://yourdomain.com'.
 */
// Fail safe: anything that isn't localhost/CLI runs as production (no error details shown to visitors)
// unless APP_ENV says otherwise.
$isLocal = PHP_SAPI === 'cli' || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);

return [
    'env'              => getenv('APP_ENV') ?: ($isLocal ? 'development' : 'production'),   // development | production
    'url'              => 'http://localhost/mach',               // no trailing slash
    // Secret used to encrypt stored secrets (e.g. SMTP password). Keep private; changing it means re-entering the SMTP password.
    'app_key'          => getenv('APP_KEY') ?: 'CHANGE-ME: run  php -r "echo base64_encode(random_bytes(32));"',
    'timezone'         => 'Asia/Kolkata',
    'session_name'     => 'mach_sid',
    'upload_max_bytes' => 2 * 1024 * 1024,
    'log_path'         => dirname(__DIR__) . '/storage/logs',
];
