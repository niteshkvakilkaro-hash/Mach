<?php
/**
 * Technicians: admin management + the technician job app.
 * A technician may have a login (users row with role "technician", linked by technicians.user_id).
 */

const TECHNICIAN_STATUSES = ['active' => 'Active', 'on_leave' => 'On Leave', 'inactive' => 'Inactive'];

/**
 * Next step a technician takes from each booking status: [target status, button label, icon, needs amount field].
 * "quoted_amount" / "final_amount" name the amount the technician must enter for that step.
 */
const TECH_NEXT_STEP = [
    'confirmed'           => ['accepted', 'Accept job', 'bi-hand-thumbs-up', null],
    'technician_assigned' => ['accepted', 'Accept job', 'bi-hand-thumbs-up', null],
    'rescheduled'         => ['accepted', 'Accept new time', 'bi-hand-thumbs-up', null],
    'accepted'            => ['on_the_way', 'I’m on the way', 'bi-scooter', null],
    'on_the_way'          => ['arrived', 'I’ve arrived', 'bi-geo-alt', null],
    'arrived'             => ['inspection', 'Start inspection', 'bi-search', null],
    'inspection'          => ['quotation_sent', 'Send quotation', 'bi-receipt', 'quoted_amount'],
    'quotation_sent'      => ['approved', 'Customer approved', 'bi-check2-circle', null],
    'approved'            => ['in_progress', 'Start repair', 'bi-tools', null],
    'in_progress'         => ['payment_pending', 'Repair done — collect payment', 'bi-cash-coin', 'final_amount'],
    'payment_pending'     => ['completed', 'Mark job completed', 'bi-check2-all', 'final_amount'],
];

// ================================================================ admin side

function technician_find(int $id): ?array
{
    $t = db_one(
        'SELECT t.*, u.email AS login_email, u.status AS login_status, u.last_login_at
         FROM technicians t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.deleted_at IS NULL',
        [$id]
    );
    if ($t) {
        $t['service_ids'] = array_map('intval', array_column(db_all('SELECT service_id FROM technician_services WHERE technician_id = ?', [$id]), 'service_id'));
        $t['location_ids'] = array_map('intval', array_column(db_all('SELECT location_id FROM technician_locations WHERE technician_id = ?', [$id]), 'location_id'));
    }
    return $t;
}

/** List with per-technician job counts in one grouped query. */
function technician_list(string $status, string $q): array
{
    $where = 't.deleted_at IS NULL';
    $params = [];
    if (isset(TECHNICIAN_STATUSES[$status])) {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where .= ' AND (t.name LIKE ? OR t.phone LIKE ? OR t.specialization LIKE ? OR t.service_areas LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    return db_all(
        "SELECT t.*, u.status AS login_status,
                SUM(b.scheduled_date = CURDATE() AND bs.is_closed = 0) AS today,
                SUM(bs.is_closed = 0) AS pending,
                SUM(b.status = 'completed' AND b.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_completed
         FROM technicians t
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN bookings b ON b.technician_id = t.id AND b.deleted_at IS NULL
         LEFT JOIN booking_statuses bs ON bs.slug = b.status
         WHERE $where GROUP BY t.id ORDER BY FIELD(t.status, 'active', 'on_leave', 'inactive'), t.name",
        $params
    );
}

function technician_stats(int $id): array
{
    $row = db_one(
        "SELECT SUM(b.scheduled_date = CURDATE() AND bs.is_closed = 0) AS today,
                SUM(bs.is_closed = 0) AS pending,
                SUM(b.status = 'completed') AS completed,
                SUM(b.status = 'cancelled') AS cancelled,
                COUNT(b.id) AS total,
                COALESCE(SUM(IF(b.status = 'completed', b.final_amount - b.discount_amount, 0)), 0) AS job_value,
                COALESCE(SUM(IF(b.status = 'completed' AND b.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), b.final_amount - b.discount_amount, 0)), 0) AS month_value
         FROM bookings b JOIN booking_statuses bs ON bs.slug = b.status
         WHERE b.technician_id = ? AND b.deleted_at IS NULL",
        [$id]
    );
    $row['collected'] = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by_technician = ? AND status = 'paid'", [$id]);
    return array_map(fn($v) => $v === null ? 0 : $v, $row);
}

function technician_validate(array $in, ?int $id): array
{
    $errors = [];
    $name = clean_str($in['name'] ?? '', 100);
    if (mb_strlen($name) < 2) $errors['name'] = 'Enter the technician name.';
    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    elseif (db_value('SELECT 1 FROM technicians WHERE phone = ? AND id <> ?', [$phone, $id ?? 0])) $errors['phone'] = 'Another technician already uses this number.';
    $email = clean_str($in['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
    $exp = trim((string) ($in['experience_years'] ?? ''));
    if ($exp !== '' && (!ctype_digit($exp) || (int) $exp > 60)) $errors['experience_years'] = 'Enter years (0–60).';
    $joining = (string) ($in['joining_date'] ?? '');
    if ($joining !== '' && !DateTime::createFromFormat('!Y-m-d', $joining)) $errors['joining_date'] = 'Invalid date.';
    $commission = trim((string) ($in['commission_percent'] ?? ''));
    if ($commission !== '' && (!is_numeric($commission) || $commission < 0 || $commission > 100)) $errors['commission_percent'] = '0 to 100.';

    $validServices = array_map('intval', array_column(get_services(), 'id'));
    $services = array_values(array_intersect(array_map('intval', (array) ($in['services'] ?? [])), $validServices));
    $validAreas = [];
    foreach (get_locations() as $c) foreach ($c['areas'] as $a) $validAreas[] = (int) $a['id'];
    $areas = array_values(array_intersect(array_map('intval', (array) ($in['locations'] ?? [])), $validAreas));

    return [[
        'name' => $name, 'phone' => $phone, 'email' => $email ?: null,
        'specialization' => clean_str($in['specialization'] ?? '', 255) ?: null,
        'experience_years' => $exp === '' ? null : (int) $exp,
        'service_areas' => clean_str($in['service_areas'] ?? '', 500) ?: null,
        'address' => clean_str($in['address'] ?? '', 500) ?: null,
        'status' => isset(TECHNICIAN_STATUSES[$in['status'] ?? '']) ? $in['status'] : 'active',
        'joining_date' => $joining ?: null,
        'commission_percent' => $commission === '' ? 0 : round((float) $commission, 2),
    ], $services, $areas, $errors];
}

/** Insert or update a technician with their services and areas. Returns the id. */
function technician_save(?array $existing, array $d, array $services, array $areas, int $userId): int
{
    return db_transaction(function () use ($existing, $d, $services, $areas, $userId) {
        if ($existing) {
            $id = (int) $existing['id'];
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
            db_query("UPDATE technicians SET $sets WHERE id = ?", array_merge(array_values($d), [$id]));
            audit_log($userId, 'updated', 'technicians', $id, "Edited technician {$d['name']}", array_intersect_key($existing, $d), $d);
            // Deactivating a technician also blocks their app login
            if ($existing['user_id'] && $d['status'] === 'inactive') {
                db_query("UPDATE users SET status = 'inactive' WHERE id = ?", [$existing['user_id']]);
            }
        } else {
            $id = db_insert('technicians', $d);
            audit_log($userId, 'created', 'technicians', $id, "Created technician {$d['name']}");
        }
        db_query('DELETE FROM technician_services WHERE technician_id = ?', [$id]);
        foreach ($services as $sid) db_insert('technician_services', ['technician_id' => $id, 'service_id' => $sid]);
        db_query('DELETE FROM technician_locations WHERE technician_id = ?', [$id]);
        foreach ($areas as $lid) db_insert('technician_locations', ['technician_id' => $id, 'location_id' => $lid]);
        return $id;
    });
}

/**
 * Create, update or disable the technician's app login.
 * $password null = keep current password (or error if a new login needs one).
 */
function technician_set_login(array $t, bool $enabled, string $email, ?string $password, int $userId): ?string
{
    if (!$enabled) {
        if ($t['user_id']) {
            db_query("UPDATE users SET status = 'inactive' WHERE id = ?", [$t['user_id']]);
            audit_log($userId, 'login_disabled', 'technicians', (int) $t['id'], "Disabled app login for {$t['name']}");
        }
        return null;
    }
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid login email.';
    if (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $t['user_id'] ?? 0])) return 'This login email is already used by another account.';
    if ($password !== null && (strlen($password) < 8 || !preg_match('/\d/', $password))) return 'Password must be at least 8 characters and include a number.';

    $roleId = (int) db_value("SELECT id FROM roles WHERE slug = 'technician'");
    if ($t['user_id']) {
        $sql = 'UPDATE users SET email = ?, name = ?, phone = ?, status = ?' . ($password !== null ? ', password_hash = ?, password_changed_at = NOW()' : '') . ' WHERE id = ?';
        $params = [$email, $t['name'], $t['phone'], $t['status'] === 'inactive' ? 'inactive' : 'active'];
        if ($password !== null) $params[] = password_hash($password, PASSWORD_DEFAULT);
        $params[] = $t['user_id'];
        db_query($sql, $params);
        audit_log($userId, 'login_updated', 'technicians', (int) $t['id'], "Updated app login for {$t['name']}" . ($password !== null ? ' (password reset)' : ''));
    } else {
        if ($password === null) return 'Set a password for the new login.';
        $uid = db_insert('users', ['role_id' => $roleId, 'name' => $t['name'], 'email' => $email, 'phone' => $t['phone'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active', 'password_changed_at' => date('Y-m-d H:i:s')]);
        db_query('UPDATE technicians SET user_id = ? WHERE id = ?', [$uid, $t['id']]);
        audit_log($userId, 'login_created', 'technicians', (int) $t['id'], "Created app login for {$t['name']}");
    }
    return null;
}

// ================================================================ technician app

function technician_for_user(int $userId): ?array
{
    return db_one("SELECT * FROM technicians WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
}

/** Jobs for the app: today (incl. overdue open jobs), upcoming, or recently done. */
function tech_jobs(int $technicianId, string $view): array
{
    $cond = match ($view) {
        'upcoming' => "b.scheduled_date > CURDATE() AND bs.is_closed = 0",
        'done'     => "bs.is_closed = 1 AND b.scheduled_date >= CURDATE() - INTERVAL 30 DAY",
        default    => "b.scheduled_date <= CURDATE() AND (bs.is_closed = 0 OR DATE(b.completed_at) = CURDATE())",
    };
    $order = $view === 'done' ? 'b.scheduled_date DESC, b.id DESC' : 'b.scheduled_date, b.scheduled_time, b.id';
    return db_all(
        "SELECT b.booking_number, b.scheduled_date, b.scheduled_time, b.address, b.status, b.estimated_amount, b.final_amount,
                c.name AS customer_name, c.phone, s.name AS service_name, loc.name AS area_name, b.city,
                bs.label AS status_label, bs.color AS status_color, bs.is_closed
         FROM bookings b
         JOIN booking_statuses bs ON bs.slug = b.status
         JOIN customers c ON c.id = b.customer_id
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN locations loc ON loc.id = b.location_id
         WHERE b.technician_id = ? AND b.deleted_at IS NULL AND $cond
         ORDER BY $order LIMIT 100",
        [$technicianId]
    );
}

/** A booking, only if it belongs to this technician. */
function tech_job(string $number, int $technicianId): ?array
{
    $b = booking_find($number);
    return $b && (int) $b['technician_id'] === $technicianId ? $b : null;
}

/** Statuses a technician may set (lookup flag), in flow order. */
function tech_allowed_statuses(): array
{
    return array_filter(booking_statuses(), fn($s) => (int) $s['technician_can_set'] === 1);
}

function maps_link(string $address, ?string $area, ?string $city): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($address . ', ' . ($area ? $area . ', ' : '') . ($city ?? ''), ', '));
}

/** Guard for technician app pages: signed-in user with jobs.view_own and a linked technician profile. */
function tech_require(): array
{
    $me = require_permission('jobs.view_own');
    $tech = technician_for_user((int) $me['id']);
    if (!$tech) {
        if (is_api_request()) {
            json_response(false, 'Your account is not linked to a technician profile.', [], 403);
        }
        http_response_code(403);
        exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#07051a;color:#ece9f8;font-family:system-ui;padding:40px;text-align:center">'
            . '<h1 style="font-size:22px">No technician profile</h1><p style="color:#a39ebf">This login is not linked to a technician. Ask the admin to enable app login from the technician\'s page.</p>'
            . '<p><a style="color:#f0abfc" href="' . e(url('admin/dashboard')) . '">Go to admin</a></p></body>');
    }
    if ($tech['status'] === 'inactive') {
        auth_logout();
        flash_set('login_notice', 'Your technician account is inactive.');
        redirect(url('admin/login'));
    }
    return [$me, $tech];
}

/** WhatsApp text from the technician to the customer. */
function tech_whatsapp_text(array $b, array $tech): string
{
    $name = $b['customer_name'] ? strtok($b['customer_name'], ' ') : 'there';
    return "Hi $name, I'm {$tech['name']} from " . setting('business_name') . ' for your '
        . ($b['service_name'] ? $b['service_name'] . ' ' : '') . "booking {$b['booking_number']}.";
}
