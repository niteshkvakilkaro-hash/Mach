<?php
/**
 * Staff authentication & role-based access control.
 *
 * Session holds only the user id and timestamps; the user row, role and permission
 * list are reloaded once per request so deactivations and permission changes apply immediately.
 */

const AUTH_IDLE_TIMEOUT = 7200;       // 2 hours without activity
const AUTH_MAX_FAILS = 5;             // failed logins …
const AUTH_FAIL_WINDOW = 900;         // … per 15 minutes, per email and per IP

/** The logged-in staff user (with role_slug, role_name, permissions) or null. */
function auth_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($refresh) {
        $user = false;
    }
    if ($user !== false) {
        return $user;
    }
    $user = null;

    $auth = $_SESSION['auth'] ?? null;
    if (!$auth || empty($auth['id'])) {
        return null;
    }
    if (time() - (int) ($auth['last'] ?? 0) > AUTH_IDLE_TIMEOUT) {
        auth_logout();
        flash_set('login_notice', 'You were signed out after 2 hours of inactivity.');
        return null;
    }

    $row = db_one(
        "SELECT u.id, u.name, u.email, u.phone, u.avatar, u.status, u.role_id, r.slug AS role_slug, r.name AS role_name
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.deleted_at IS NULL",
        [(int) $auth['id']]
    );
    if (!$row || $row['status'] !== 'active') {
        auth_logout();
        return null;
    }

    $row['permissions'] = array_column(db_all(
        'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
        [(int) $row['role_id']]
    ), 'slug');

    $_SESSION['auth']['last'] = time();
    return $user = $row;
}

function auth_id(): ?int
{
    $u = auth_user();
    return $u ? (int) $u['id'] : null;
}

function can(string $permission): bool
{
    $u = auth_user();
    if (!$u) {
        return false;
    }
    return $u['role_slug'] === 'super_admin' || in_array($permission, $u['permissions'], true);
}

/**
 * Try to sign in. Returns null on success or an error message.
 * Failures are rate-limited per email and per IP; the message never reveals which part was wrong.
 */
function auth_attempt(string $email, string $password): ?string
{
    $email = strtolower(trim($email));
    $ip = client_ip();

    if (rate_limit_count('login_fail_email', $email, AUTH_FAIL_WINDOW) >= AUTH_MAX_FAILS
        || rate_limit_count('login_fail_ip', $ip, AUTH_FAIL_WINDOW) >= AUTH_MAX_FAILS * 3) {
        return 'Too many failed attempts. Please wait 15 minutes and try again.';
    }

    $user = db_one(
        "SELECT u.id, u.password_hash, u.status, r.slug AS role_slug
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.deleted_at IS NULL",
        [$email]
    );

    // Verify against a dummy hash when the user doesn't exist, so timing doesn't leak valid emails.
    $hash = $user['password_hash'] ?? '$2y$10$yyJP29MwUKCTeJydkjndDO3Q7JbtV0H.HXDfZOH2s.Eube08RAcCG';
    $valid = password_verify($password, $hash) && $user !== null;

    if (!$valid) {
        rate_limit_hit('login_fail_email', $email);
        rate_limit_hit('login_fail_ip', $ip);
        app_log('notice', 'Failed login', ['email' => $email, 'ip' => $ip]);
        return 'Incorrect email or password.';
    }
    if ($user['status'] !== 'active') {
        return 'Your account is inactive. Please contact the administrator.';
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['auth'] = ['id' => (int) $user['id'], 'login' => time(), 'last' => time()];
    unset($_SESSION['_csrf']);   // fresh CSRF token for the new privilege level

    rate_limit_clear('login_fail_email', $email);
    db_query('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, $user['id']]);
    audit_log((int) $user['id'], 'login', 'auth', (int) $user['id'], 'Signed in');
    auth_user(true);
    return null;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    session_start();       // new empty session so flash messages still work
    session_regenerate_id(true);
}

/** Where to send a user right after login: technicians get their job app, staff the dashboard. */
function auth_home(): string
{
    $u = auth_user();
    return $u && $u['role_slug'] === 'technician' ? url('technician') : url('admin/dashboard');
}

/** Only allow post-login redirects back into this site's admin or technician area (matching the user's role). */
function safe_admin_redirect(?string $next): string
{
    $next = (string) $next;
    $u = auth_user();
    $prefix = base_path() . ($u && $u['role_slug'] === 'technician' ? '/technician' : '/admin/');
    if ($next !== '' && str_starts_with($next, $prefix) && !str_contains($next, '//') && !preg_match('/[\r\n]/', $next)) {
        return $next;
    }
    return auth_home();
}

function require_login(): array
{
    $user = auth_user();
    if ($user) {
        return $user;
    }
    if (is_api_request()) {
        json_response(false, 'Please sign in again.', [], 401);
    }
    redirect(url('admin/login') . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
}

/** Abort with 403 unless the user has the permission. */
function require_permission(string $permission): array
{
    $user = require_login();
    if (can($permission)) {
        return $user;
    }
    if ($user['role_slug'] === 'technician' && !is_api_request()) {
        redirect(url('technician'));       // technicians only use their job app
    }
    audit_log((int) $user['id'], 'denied', 'auth', null, 'Access denied: ' . $permission);
    if (is_api_request()) {
        json_response(false, 'You do not have permission to do this.', [], 403);
    }
    http_response_code(403);
    $admin = ['title' => 'Access denied', 'active' => ''];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-shield-lock"></i><h2>Access denied</h2>'
        . '<p>Your role doesn\'t have access to this page. Ask an administrator if you need it.</p>'
        . '<a class="btn btn-grad" href="' . e(auth_home()) . '">Back to dashboard</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}

/** For POST endpoints in the admin: logged in + CSRF + permission. */
function require_admin_post(string $permission): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(false, 'Method not allowed.', [], 405);
    }
    $user = require_permission($permission);
    if (!csrf_verify()) {
        json_response(false, 'Your session expired. Refresh the page and try again.', [], 419);
    }
    return $user;
}
