<?php
/**
 * Shared helpers: escaping, URLs, settings, phone numbers, numbering, rate limiting,
 * notifications, audit log and light text rendering.
 */

// ---------------------------------------------------------------- output

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding inside <script> tags. */
function js_json($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/**
 * Render admin-entered text safely. Everything is escaped first; then
 * "## " lines become headings, "- " lines become list items, blank lines split paragraphs.
 */
function render_text(?string $text): string
{
    $html = '';
    $list = false;
    $para = [];
    $flush = function () use (&$html, &$para) {
        if ($para) {
            $html .= '<p>' . implode('<br>', $para) . '</p>';
            $para = [];
        }
    };
    foreach (preg_split('/\R/', trim((string) $text)) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '- ')) {
            $flush();
            if (!$list) { $html .= '<ul>'; $list = true; }
            $html .= '<li>' . e(substr($line, 2)) . '</li>';
            continue;
        }
        if ($list) { $html .= '</ul>'; $list = false; }
        if ($line === '') {
            $flush();
        } elseif (str_starts_with($line, '## ')) {
            $flush();
            $html .= '<h2>' . e(substr($line, 3)) . '</h2>';
        } else {
            $para[] = e($line);
        }
    }
    if ($list) { $html .= '</ul>'; }
    $flush();
    return $html;
}

function format_inr($amount, bool $decimals = false): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    return '₹' . number_format((float) $amount, $decimals ? 2 : 0);
}

// ---------------------------------------------------------------- URLs & requests

function base_path(): string
{
    return rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
}

function url(string $path = ''): string
{
    return rtrim(config('app.url'), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = ROOT_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 1;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function current_path(): string
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return '/' . trim($path, '/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    exit;
}

/** Only REMOTE_ADDR is trusted; add proxy handling here if you deploy behind a load balancer. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function json_response(bool $success, string $message, array $data = [], int $status = 200, array $errors = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = ['success' => $success, 'message' => $message, 'data' => $data ?: new stdClass()];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Safe CSP subset: no framing by other sites, no plugins, no <base> hijack, forms only post to this site.
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function flash_set(string $key, $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

function flash_get(string $key, $default = null)
{
    $value = $_SESSION['_flash'][$key] ?? $default;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

// ---------------------------------------------------------------- settings

/** All settings are loaded with one query per request. */
function setting(string $key, string $default = ''): string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $settings[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return ($settings[$key] ?? '') !== '' ? $settings[$key] : $default;
}

function time_slots(): array
{
    return array_values(array_filter(array_map('trim', explode('|', setting('booking_time_slots')))));
}

// ---------------------------------------------------------------- phone & contact links

/** Normalise an Indian mobile number to 10 digits, or null if invalid. */
function normalize_phone(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
}

function tel_link(?string $phone = null): string
{
    $phone = $phone ?? setting('phone');
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}

function whatsapp_link(string $text = '', ?string $number = null): string
{
    $number = preg_replace('/\D+/', '', $number ?? setting('whatsapp'));
    return 'https://wa.me/' . $number . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

// ---------------------------------------------------------------- strings

function clean_str($value, int $max = 255): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string) $value)) ?? '';
    return mb_substr($value, 0, $max);
}

function slugify(string $text): string
{
    $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $text !== '' ? $text : 'item';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

// ---------------------------------------------------------------- numbering

/**
 * Next public number, e.g. next_number('LEAD') => LEAD-2026-000001.
 * LAST_INSERT_ID(expr) makes the increment atomic per connection.
 */
function next_number(string $prefix): string
{
    $year = (int) date('Y');
    db_query('INSERT IGNORE INTO sequences (seq_name, seq_year, last_value) VALUES (?, ?, 0)', [$prefix, $year]);
    db_query('UPDATE sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE seq_name = ? AND seq_year = ?', [$prefix, $year]);
    $n = (int) db_value('SELECT LAST_INSERT_ID()');
    return sprintf('%s-%d-%06d', $prefix, $year, $n);
}

// ---------------------------------------------------------------- rate limiting

/**
 * Returns false when $identifier has hit $max attempts for $action within $window seconds.
 * Records the attempt when allowed.
 */
function rate_limit(string $action, string $identifier, int $max, int $window): bool
{
    if (rate_limit_count($action, $identifier, $window) >= $max) {
        return false;
    }
    rate_limit_hit($action, $identifier);
    return true;
}

function rate_limit_count(string $action, string $identifier, int $window): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM rate_limits WHERE action = ? AND identifier = ? AND created_at > (NOW() - INTERVAL ? SECOND)',
        [$action, hash('sha256', $identifier), $window]
    );
}

function rate_limit_hit(string $action, string $identifier): void
{
    db_query('INSERT INTO rate_limits (action, identifier) VALUES (?, ?)', [$action, hash('sha256', $identifier)]);
    if (random_int(1, 50) === 1) {
        db_query('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    }
}

function rate_limit_clear(string $action, string $identifier): void
{
    db_query('DELETE FROM rate_limits WHERE action = ? AND identifier = ?', [$action, hash('sha256', $identifier)]);
}

// ---------------------------------------------------------------- notifications & audit

/** Fan out one notification row per active user in the given roles. */
function notify_roles(array $roleSlugs, string $type, string $title, string $message = '', string $link = ''): void
{
    if (!$roleSlugs) {
        return;
    }
    $in = implode(',', array_fill(0, count($roleSlugs), '?'));
    db_query(
        "INSERT INTO notifications (user_id, type, title, message, link)
         SELECT u.id, ?, ?, ?, ? FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.slug IN ($in) AND u.status = 'active' AND u.deleted_at IS NULL",
        array_merge([$type, mb_substr($title, 0, 150), mb_substr($message, 0, 500), $link], $roleSlugs)
    );
}

function notify_user(int $userId, string $type, string $title, string $message = '', string $link = ''): void
{
    db_insert('notifications', [
        'user_id' => $userId,
        'type'    => $type,
        'title'   => mb_substr($title, 0, 150),
        'message' => mb_substr($message, 0, 500),
        'link'    => $link,
    ]);
}

function audit_log(?int $userId, string $action, string $module, ?int $recordId, string $description, ?array $old = null, ?array $new = null): void
{
    db_insert('audit_logs', [
        'user_id'     => $userId,
        'action'      => $action,
        'module'      => $module,
        'record_id'   => $recordId,
        'description' => mb_substr($description, 0, 500),
        'old_values'  => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
        'new_values'  => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
        'ip_address'  => client_ip(),
        'user_agent'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

/** "5 min ago" style relative time. */
function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return date('d M Y', strtotime($datetime));
}

/** Rate as a percentage with one decimal, safe for zero. */
function pct($part, $whole): float
{
    return $whole ? round($part * 100 / $whole, 1) : 0.0;
}
