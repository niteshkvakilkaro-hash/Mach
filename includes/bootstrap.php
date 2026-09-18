<?php
/**
 * Single entry point for every PHP page and API endpoint.
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$GLOBALS['__config'] = [
    'app' => require ROOT_PATH . '/config/app.php',
    'db'  => require ROOT_PATH . '/config/database.php',
];

/** Read config with dot notation: config('app.url') */
function config(string $key, $default = null)
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

date_default_timezone_set(config('app.timezone', 'Asia/Kolkata'));

require ROOT_PATH . '/includes/errors.php';
require ROOT_PATH . '/includes/db.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/csrf.php';
require ROOT_PATH . '/includes/auth.php';
require ROOT_PATH . '/includes/models/content.php';
require ROOT_PATH . '/includes/models/leads.php';
require ROOT_PATH . '/includes/models/lead-admin.php';
require ROOT_PATH . '/includes/models/bookings.php';
require ROOT_PATH . '/includes/models/technicians.php';
require ROOT_PATH . '/includes/models/payments.php';
require ROOT_PATH . '/includes/uploads.php';
require ROOT_PATH . '/includes/mailer.php';
require ROOT_PATH . '/includes/models/quality.php';
require ROOT_PATH . '/includes/admin/helpers.php';

register_error_handlers();

// Session
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(config('app.session_name', 'app_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

send_security_headers();
