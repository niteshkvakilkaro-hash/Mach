<?php
/**
 * Bookings and customers.
 * A booking always belongs to a customer (found by phone, or created once — never duplicated).
 * Booking progress pushes the linked lead forward (never backwards).
 */

/** Booking status → lead status it implies. Only applied when it moves the lead forward. */
const BOOKING_TO_LEAD_STATUS = [
    'confirmed'           => 'scheduled',
    'technician_assigned' => 'technician_assigned',
    'accepted'            => 'technician_assigned',
    'inspection'          => 'visit_completed',
    'quotation_sent'      => 'quotation_sent',
    'approved'            => 'in_progress',
    'in_progress'         => 'in_progress',
    'payment_pending'     => 'payment_pending',
    'completed'           => 'completed',
];
/** Visual job flow on the booking page. */
const BOOKING_FLOW = ['confirmed', 'technician_assigned', 'accepted', 'on_the_way', 'arrived', 'inspection', 'quotation_sent', 'approved', 'in_progress', 'payment_pending', 'completed'];

function booking_statuses(): array
{
    static $cache = null;
    return $cache ??= array_column(db_all('SELECT * FROM booking_statuses ORDER BY sort_order'), null, 'slug');
}

// ================================================================ customers

/** Find the customer for a phone number, or create one. Links that phone's leads to the customer. */
function customer_ensure(array $d, int $userId): int
{
    $id = db_value('SELECT id FROM customers WHERE phone = ? AND deleted_at IS NULL', [$d['phone']]);
    if (!$id) {
        // A soft-deleted customer with this phone is restored instead of duplicated.
        $deleted = db_value('SELECT id FROM customers WHERE phone = ?', [$d['phone']]);
        if ($deleted) {
            db_query('UPDATE customers SET deleted_at = NULL WHERE id = ?', [$deleted]);
            $id = $deleted;
        } else {
            $id = db_insert('customers', [
                'customer_number' => next_number('CUS'),
                'name'        => $d['name'] ?: 'Customer',
                'phone'       => $d['phone'],
                'email'       => $d['email'] ?? null,
                'address'     => $d['address'] ?? null,
                'city'        => $d['city'] ?? null,
                'pincode'     => $d['pincode'] ?? null,
                'location_id' => $d['location_id'] ?? null,
                'source'      => $d['source'] ?? null,
            ]);
            audit_log($userId, 'created', 'customers', (int) $id, 'Customer created for ' . $d['phone']);
        }
    }
    db_query('UPDATE leads SET customer_id = ? WHERE phone = ? AND customer_id IS NULL', [$id, $d['phone']]);
    return (int) $id;
}

/** Recalculate cached totals on the customer row from bookings and payments. */
function customer_refresh_stats(int $customerId): void
{
    db_query(
        "UPDATE customers c SET
            total_bookings = (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id AND b.deleted_at IS NULL AND b.status <> 'cancelled'),
            total_spent = (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.customer_id = c.id AND p.status = 'paid'),
            last_service_date = (SELECT MAX(b.scheduled_date) FROM bookings b WHERE b.customer_id = c.id AND b.deleted_at IS NULL AND b.status = 'completed')
         WHERE c.id = ?",
        [$customerId]
    );
}

function customer_find(string $number): ?array
{
    return db_one(
        'SELECT c.*, loc.name AS area_name FROM customers c LEFT JOIN locations loc ON loc.id = c.location_id
         WHERE c.customer_number = ? AND c.deleted_at IS NULL',
        [$number]
    );
}

function customer_search(string $q, string $sort, int $page, int $perPage = 25): array
{
    $where = 'c.deleted_at IS NULL';
    $params = [];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/\D+/', '', $q);
        $where .= ' AND (c.name LIKE ? OR c.email LIKE ? OR c.customer_number LIKE ?' . (strlen($digits) >= 4 ? ' OR c.phone LIKE ? OR c.alt_phone LIKE ?' : '') . ')';
        array_push($params, $like, $like, $like);
        if (strlen($digits) >= 4) {
            array_push($params, '%' . substr($digits, -10) . '%', '%' . substr($digits, -10) . '%');
        }
    }
    $orders = ['recent' => 'c.id DESC', 'spent' => 'c.total_spent DESC, c.id DESC', 'bookings' => 'c.total_bookings DESC, c.id DESC', 'last' => 'c.last_service_date IS NULL, c.last_service_date DESC'];
    $order = $orders[$sort] ?? $orders['recent'];
    $total = (int) db_value("SELECT COUNT(*) FROM customers c WHERE $where", $params);
    $offset = ($page - 1) * $perPage;
    $rows = db_all(
        "SELECT c.*, loc.name AS area_name FROM customers c LEFT JOIN locations loc ON loc.id = c.location_id
         WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset",
        $params
    );
    return ['total' => $total, 'rows' => $rows];
}

function customer_validate(array $in): array
{
    $errors = [];
    $name = clean_str($in['name'] ?? '', 100);
    if (mb_strlen($name) < 2) $errors['name'] = 'Enter the customer name.';
    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    $altRaw = clean_str($in['alt_phone'] ?? '', 20);
    $alt = $altRaw === '' ? null : normalize_phone($altRaw);
    if ($altRaw !== '' && $alt === null) $errors['alt_phone'] = 'Enter a valid 10-digit number or leave blank.';
    $email = clean_str($in['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
    $pincode = clean_str($in['pincode'] ?? '', 10);
    if ($pincode !== '' && !preg_match('/^[1-9]\d{5}$/', $pincode)) $errors['pincode'] = 'Enter a valid 6-digit pincode.';
    $locationId = (int) ($in['location_id'] ?? 0);
    $valid = false;
    foreach (get_locations() as $c) foreach ($c['areas'] as $a) if ((int) $a['id'] === $locationId) $valid = true;
    return [[
        'name' => $name, 'phone' => $phone, 'alt_phone' => $alt, 'email' => $email ?: null,
        'address' => clean_str($in['address'] ?? '', 500) ?: null, 'city' => clean_str($in['city'] ?? '', 80) ?: null,
        'pincode' => $pincode ?: null, 'location_id' => $valid ? $locationId : null, 'notes' => clean_str($in['notes'] ?? '', 2000) ?: null,
    ], $errors];
}

// ================================================================ bookings: read

function booking_filters(array $q): array
{
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
    $views = ['today', 'upcoming', 'unassigned', 'open', 'completed', 'cancelled', 'all'];
    return [
        'view'       => in_array($q['view'] ?? '', $views, true) ? $q['view'] : 'today',
        'q'          => clean_str($q['q'] ?? '', 100),
        'status'     => isset(booking_statuses()[$q['status'] ?? '']) ? $q['status'] : '',
        'technician' => ($q['technician'] ?? '') === 'none' ? 'none' : ((int) ($q['technician'] ?? 0) ?: ''),
        'service'    => (int) ($q['service'] ?? 0) ?: '',
        'from'       => $date($q['from'] ?? ''),
        'to'         => $date($q['to'] ?? ''),
    ];
}

function booking_where(array $f, ?string $view = null): array
{
    $view = $view ?? $f['view'];
    $sql = 'b.deleted_at IS NULL';
    $params = [];
    $open = "b.status IN (SELECT slug FROM booking_statuses WHERE is_closed = 0)";
    $sql .= match ($view) {
        'today'      => " AND b.scheduled_date = CURDATE() AND b.status <> 'cancelled'",
        'upcoming'   => " AND b.scheduled_date > CURDATE() AND $open",
        'unassigned' => " AND b.technician_id IS NULL AND $open",
        'open'       => " AND $open",
        'completed'  => " AND b.status = 'completed'",
        'cancelled'  => " AND b.status = 'cancelled'",
        default      => '',
    };
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $digits = preg_replace('/\D+/', '', $f['q']);
        $sql .= ' AND (b.booking_number LIKE ? OR c.name LIKE ?' . (strlen($digits) >= 4 ? ' OR c.phone LIKE ?' : '') . ')';
        array_push($params, $like, $like);
        if (strlen($digits) >= 4) $params[] = '%' . substr($digits, -10) . '%';
    }
    if ($f['status'] !== '') { $sql .= ' AND b.status = ?'; $params[] = $f['status']; }
    if ($f['technician'] === 'none') { $sql .= ' AND b.technician_id IS NULL'; }
    elseif ($f['technician'] !== '') { $sql .= ' AND b.technician_id = ?'; $params[] = $f['technician']; }
    if ($f['service'] !== '') { $sql .= ' AND b.service_id = ?'; $params[] = $f['service']; }
    if ($f['from'] !== '') { $sql .= ' AND b.scheduled_date >= ?'; $params[] = $f['from']; }
    if ($f['to'] !== '') { $sql .= ' AND b.scheduled_date <= ?'; $params[] = $f['to']; }
    return [$sql, $params];
}

function booking_search(array $f, int $page, int $perPage = 25): array
{
    [$where, $params] = booking_where($f);
    $join = 'FROM bookings b JOIN customers c ON c.id = b.customer_id';
    $total = (int) db_value("SELECT COUNT(*) $join WHERE $where", $params);
    $order = in_array($f['view'], ['completed', 'cancelled', 'all'], true) ? 'b.scheduled_date DESC, b.id DESC' : 'b.scheduled_date, b.scheduled_time, b.id';
    $offset = ($page - 1) * $perPage;
    $rows = db_all(
        "SELECT b.*, c.name AS customer_name, c.phone, c.customer_number, s.name AS service_name, t.name AS technician_name,
                loc.name AS area_name, bs.label AS status_label, bs.color AS status_color, l.lead_number
         $join
         JOIN booking_statuses bs ON bs.slug = b.status
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN technicians t ON t.id = b.technician_id
         LEFT JOIN locations loc ON loc.id = b.location_id
         LEFT JOIN leads l ON l.id = b.lead_id
         WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset",
        $params
    );
    return ['total' => $total, 'rows' => $rows];
}

function booking_view_counts(array $f): array
{
    $out = [];
    foreach (['today', 'upcoming', 'unassigned', 'open', 'completed', 'cancelled', 'all'] as $v) {
        [$where, $params] = booking_where($f, $v);
        $out[$v] = (int) db_value("SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE $where", $params);
    }
    return $out;
}

function booking_find(string $number): ?array
{
    return db_one(
        "SELECT b.*, c.name AS customer_name, c.phone, c.alt_phone, c.email, c.customer_number,
                s.name AS service_name, s.warranty AS service_warranty, t.name AS technician_name, t.phone AS technician_phone, t.user_id AS technician_user_id,
                loc.name AS area_name, bs.label AS status_label, bs.color AS status_color, bs.is_closed,
                l.lead_number, cp.code AS coupon_code, cr.name AS created_by_name
         FROM bookings b
         JOIN customers c ON c.id = b.customer_id
         JOIN booking_statuses bs ON bs.slug = b.status
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN technicians t ON t.id = b.technician_id
         LEFT JOIN locations loc ON loc.id = b.location_id
         LEFT JOIN leads l ON l.id = b.lead_id
         LEFT JOIN coupons cp ON cp.id = b.coupon_id
         LEFT JOIN users cr ON cr.id = b.created_by
         WHERE b.booking_number = ? AND b.deleted_at IS NULL",
        [$number]
    );
}

function booking_or_404(string $number): array
{
    $b = booking_find(clean_str($number, 20));
    if (!$b) {
        json_response(false, 'Booking not found.', [], 404);
    }
    return $b;
}

function booking_history(int $bookingId): array
{
    return db_all(
        'SELECT h.*, u.name AS user_name FROM booking_status_history h LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.booking_id = ? ORDER BY h.id DESC',
        [$bookingId]
    );
}

function booking_payments(int $bookingId): array
{
    return db_all('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC', [$bookingId]);
}

/** Technicians with their job count (and booked slots) on a date — for picking who is free. */
function technician_day_load(string $date, ?int $excludeBookingId = null): array
{
    $rows = db_all(
        "SELECT t.id, t.name, t.specialization, t.status,
                COUNT(b.id) AS jobs, GROUP_CONCAT(b.scheduled_time ORDER BY b.scheduled_time SEPARATOR '|') AS slots
         FROM technicians t
         LEFT JOIN bookings b ON b.technician_id = t.id AND b.scheduled_date = ? AND b.deleted_at IS NULL AND b.id <> ?
              AND b.status NOT IN ('cancelled', 'completed')
         WHERE t.deleted_at IS NULL AND t.status <> 'inactive'
         GROUP BY t.id ORDER BY t.status = 'on_leave', jobs, t.name",
        [$date, $excludeBookingId ?? 0]
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['jobs'] = (int) $r['jobs'];
        $r['slots'] = $r['slots'] ? explode('|', $r['slots']) : [];
    }
    return $rows;
}

// ================================================================ bookings: write

function booking_validate(array $in): array
{
    $errors = [];
    $services = array_column(get_services(), null, 'id');
    $serviceId = (int) ($in['service_id'] ?? 0);
    if (!isset($services[$serviceId])) $errors['service_id'] = 'Choose a service.';
    $date = (string) ($in['scheduled_date'] ?? '');
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) $errors['scheduled_date'] = 'Choose a visit date.';
    elseif ($date < date('Y-m-d', strtotime('-1 day'))) $errors['scheduled_date'] = 'The visit date is in the past.';
    $time = clean_str($in['scheduled_time'] ?? '', 40);
    if ($time === '') $errors['scheduled_time'] = 'Choose a time slot.';
    $address = clean_str($in['address'] ?? '', 500);
    if (mb_strlen($address) < 5) $errors['address'] = 'Enter the full visit address.';
    $techId = (int) ($in['technician_id'] ?? 0) ?: null;
    if ($techId && !db_value("SELECT 1 FROM technicians WHERE id = ? AND deleted_at IS NULL AND status <> 'inactive'", [$techId])) {
        $errors['technician_id'] = 'Technician not available.';
    }
    $money = function (string $k) use ($in, &$errors) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') return null;
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 10000000) { $errors[$k] = 'Enter a valid amount.'; return null; }
        return round((float) $v, 2);
    };
    $locationId = (int) ($in['location_id'] ?? 0);
    $valid = false;
    foreach (get_locations() as $c) foreach ($c['areas'] as $a) if ((int) $a['id'] === $locationId) $valid = true;
    $pincode = clean_str($in['pincode'] ?? '', 10);
    if ($pincode !== '' && !preg_match('/^[1-9]\d{5}$/', $pincode)) $errors['pincode'] = 'Enter a valid 6-digit pincode.';

    return [[
        'service_id' => $serviceId ?: null, 'scheduled_date' => $date, 'scheduled_time' => $time, 'address' => $address,
        'city' => clean_str($in['city'] ?? '', 80) ?: null, 'pincode' => $pincode ?: null, 'location_id' => $valid ? $locationId : null,
        'technician_id' => $techId, 'estimated_amount' => $money('estimated_amount'), 'notes' => clean_str($in['notes'] ?? '', 2000) ?: null,
    ], $errors];
}

/**
 * Create a booking for a lead (converting it) or for an existing customer.
 * @param array|null $lead     full lead row (lead_find) or null
 * @param array      $customer ['name','phone','email',…] used to find/create the customer
 */
function booking_create(?array $lead, array $customer, array $d, int $userId): array
{
    $result = db_transaction(function () use ($lead, $customer, $d, $userId) {
        $customerId = customer_ensure($customer, $userId);
        $status = $d['technician_id'] ? 'technician_assigned' : 'confirmed';
        $number = next_number('BK');
        $id = db_insert('bookings', $d + [
            'booking_number' => $number, 'lead_id' => $lead['id'] ?? null, 'customer_id' => $customerId,
            'status' => $status, 'created_by' => $userId,
        ]);
        db_insert('booking_status_history', ['booking_id' => $id, 'old_status' => null, 'new_status' => $status, 'changed_by' => $userId,
            'note' => 'Booking created' . ($lead ? " from {$lead['lead_number']}" : '')]);

        if ($lead) {
            db_query('UPDATE leads SET customer_id = ?, converted_at = COALESCE(converted_at, NOW()) WHERE id = ?', [$customerId, $lead['id']]);
            if ($d['technician_id'] && (int) $lead['technician_id'] !== (int) $d['technician_id']) {
                db_query('UPDATE leads SET technician_id = ? WHERE id = ?', [$d['technician_id'], $lead['id']]);
                db_insert('lead_assignments', ['lead_id' => $lead['id'], 'assignment_type' => 'technician', 'technician_id' => $d['technician_id'],
                    'assigned_by' => $userId, 'note' => "Assigned with booking $number"]);
            }
            booking_sync_lead($lead, $status, $userId, "Booking $number created");
        }
        customer_refresh_stats($customerId);
        audit_log($userId, 'created', 'bookings', $id, "Created booking $number" . ($lead ? " for {$lead['lead_number']}" : ''));
        return ['id' => $id, 'booking_number' => $number, 'customer_id' => $customerId];
    });

    $when = date('D, d M', strtotime($d['scheduled_date'])) . ', ' . $d['scheduled_time'];
    try {
        notify_roles(['super_admin', 'admin', 'manager'], 'booking.created', "Booking {$result['booking_number']} created",
            ($customer['name'] ?: $customer['phone']) . ' · ' . $when, 'admin/bookings/view?number=' . $result['booking_number']);
        booking_notify_technician($d['technician_id'], "New job: {$result['booking_number']}", $when, $result['booking_number']);
    } catch (Throwable $e) {
        app_log('warning', 'Booking notification failed: ' . $e->getMessage());
    }
    mail_event('mail_event_booking_confirmation', $result['booking_number']);
    return $result;
}

function booking_notify_technician(?int $technicianId, string $title, string $message, string $number): void
{
    if (!$technicianId) return;
    $userId = db_value('SELECT user_id FROM technicians WHERE id = ?', [$technicianId]);
    if ($userId) {
        notify_user((int) $userId, 'booking.assigned', $title, $message, 'technician/job?number=' . $number);
    }
}

/** Move the lead forward to match booking progress (never backwards, never reopen a closed lead). */
function booking_sync_lead(array $lead, string $bookingStatus, int $userId, string $note): void
{
    $target = BOOKING_TO_LEAD_STATUS[$bookingStatus] ?? null;
    $current = db_one('SELECT l.id, l.lead_number, l.status, st.sort_order, st.is_closed FROM leads l JOIN lead_statuses st ON st.slug = l.status WHERE l.id = ?', [$lead['id']]);
    if (!$target || !$current || (int) $current['is_closed']) return;
    $statuses = lead_statuses();
    if ((int) $statuses[$target]['sort_order'] > (int) $current['sort_order']) {
        lead_change_status($current, $target, $note, $userId);
    }
}

/**
 * Change booking status. Handles side effects: cancel reason, completion time, final amount,
 * warranty date, customer stats, lead sync and notifications.
 */
function booking_change_status(array $b, string $status, string $note, int $userId): void
{
    if (!isset(booking_statuses()[$status])) throw new InvalidArgumentException('Unknown status');
    if ($status === $b['status']) return;
    if ($status === 'cancelled' && mb_strlen($note) < 3) throw new InvalidArgumentException('Please give a reason for cancelling');

    db_transaction(function () use ($b, $status, $note, $userId) {
        $sets = ['status = ?'];
        $params = [$status];
        if ($status === 'cancelled') { $sets[] = 'cancel_reason = ?'; $params[] = $note; }
        if (in_array($status, ['on_the_way', 'arrived', 'inspection', 'in_progress'], true) && !$b['started_at']) { $sets[] = 'started_at = NOW()'; }
        if ($status === 'completed') {
            $sets[] = 'completed_at = NOW()';
            $sets[] = 'final_amount = COALESCE(final_amount, quoted_amount, estimated_amount)';
            $days = (int) preg_replace('/\D.*/', '', (string) ($b['service_warranty'] ?? ''));
            if ($days > 0) { $sets[] = 'warranty_until = ?'; $params[] = date('Y-m-d', strtotime("+$days days")); }
        }
        $params[] = $b['id'];
        db_query('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        db_insert('booking_status_history', ['booking_id' => $b['id'], 'old_status' => $b['status'], 'new_status' => $status,
            'changed_by' => $userId, 'note' => $note !== '' ? mb_substr($note, 0, 500) : null]);
        audit_log($userId, 'status_changed', 'bookings', (int) $b['id'], "{$b['booking_number']}: {$b['status']} → $status",
            ['status' => $b['status']], ['status' => $status]);
        if ($b['lead_id']) {
            booking_sync_lead(['id' => $b['lead_id']], $status, $userId, "Booking {$b['booking_number']}: " . booking_statuses()[$status]['label']);
        }
        customer_refresh_stats((int) $b['customer_id']);
    });

    if ($status === 'completed') {
        try {
            feedback_request((int) $b['id']);
        } catch (Throwable $e) {
            app_log('warning', 'Feedback request failed: ' . $e->getMessage());
        }
        notify_roles(['super_admin', 'admin', 'manager'], 'booking.completed', "Job completed: {$b['booking_number']}",
            ($b['customer_name'] ?? '') . ' · ' . ($b['technician_name'] ?? 'no technician'), 'admin/bookings/view?number=' . $b['booking_number']);
    }
}

function booking_assign_technician(array $b, ?int $technicianId, int $userId): void
{
    if ((int) $b['technician_id'] === (int) $technicianId) return;
    if ($technicianId && !db_value("SELECT 1 FROM technicians WHERE id = ? AND deleted_at IS NULL AND status <> 'inactive'", [$technicianId])) {
        throw new InvalidArgumentException('Technician not available');
    }
    db_transaction(function () use ($b, $technicianId, $userId) {
        db_query('UPDATE bookings SET technician_id = ? WHERE id = ?', [$technicianId, $b['id']]);
        $name = $technicianId ? db_value('SELECT name FROM technicians WHERE id = ?', [$technicianId]) : 'nobody';
        db_insert('booking_status_history', ['booking_id' => $b['id'], 'old_status' => $b['status'], 'new_status' => $b['status'],
            'changed_by' => $userId, 'note' => "Technician: " . ($b['technician_name'] ?? 'none') . " → $name"]);
        if ($b['lead_id']) {
            db_query('UPDATE leads SET technician_id = ? WHERE id = ?', [$technicianId, $b['lead_id']]);
            db_insert('lead_assignments', ['lead_id' => $b['lead_id'], 'assignment_type' => 'technician', 'technician_id' => $technicianId,
                'assigned_by' => $userId, 'note' => "Via booking {$b['booking_number']}"]);
        }
        audit_log($userId, 'assigned', 'bookings', (int) $b['id'], "{$b['booking_number']}: technician → " . ($technicianId ?? 'none'),
            ['technician_id' => $b['technician_id']], ['technician_id' => $technicianId]);
        if ($technicianId && in_array($b['status'], ['pending', 'confirmed', 'rescheduled'], true)) {
            booking_change_status($b, 'technician_assigned', 'Technician assigned', $userId);
        }
    });
    booking_notify_technician($technicianId, "New job: {$b['booking_number']}",
        date('D, d M', strtotime($b['scheduled_date'])) . ', ' . $b['scheduled_time'], $b['booking_number']);
}

function booking_reschedule(array $b, string $date, string $time, string $reason, int $userId): void
{
    db_transaction(function () use ($b, $date, $time, $reason, $userId) {
        db_query('UPDATE bookings SET scheduled_date = ?, scheduled_time = ?, reschedule_count = reschedule_count + 1, status = ? WHERE id = ?',
            [$date, $time, 'rescheduled', $b['id']]);
        $from = date('d M', strtotime($b['scheduled_date'])) . ' ' . $b['scheduled_time'];
        $to = date('d M', strtotime($date)) . ' ' . $time;
        db_insert('booking_status_history', ['booking_id' => $b['id'], 'old_status' => $b['status'], 'new_status' => 'rescheduled',
            'changed_by' => $userId, 'note' => "Moved from $from to $to" . ($reason !== '' ? " — $reason" : '')]);
        audit_log($userId, 'rescheduled', 'bookings', (int) $b['id'], "{$b['booking_number']}: $from → $to",
            ['scheduled_date' => $b['scheduled_date'], 'scheduled_time' => $b['scheduled_time']], ['scheduled_date' => $date, 'scheduled_time' => $time]);
    });
    $msg = date('D, d M', strtotime($date)) . ', ' . $time;
    notify_roles(['super_admin', 'admin', 'manager'], 'booking.rescheduled', "Booking rescheduled: {$b['booking_number']}", $msg, 'admin/bookings/view?number=' . $b['booking_number']);
    booking_notify_technician($b['technician_id'] ? (int) $b['technician_id'] : null, "Job rescheduled: {$b['booking_number']}", $msg, $b['booking_number']);
}

function booking_update_amounts(array $b, array $amounts, ?string $notes, int $userId): void
{
    $new = $amounts + ['notes' => $notes];
    $old = array_intersect_key($b, $new);
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($new)));
    db_query("UPDATE bookings SET $sets WHERE id = ?", array_merge(array_values($new), [$b['id']]));
    audit_log($userId, 'updated', 'bookings', (int) $b['id'], "{$b['booking_number']}: amounts/notes updated", $old, $new);
}

/** WhatsApp text to the customer about a booking. Only what the customer needs to know. */
function booking_whatsapp_text(array $b): string
{
    $name = $b['customer_name'] ? strtok($b['customer_name'], ' ') : 'there';
    $text = "Hi $name, your " . ($b['service_name'] ? $b['service_name'] . ' ' : '') . "booking {$b['booking_number']} with "
        . setting('business_name') . ' is on ' . date('D, d M', strtotime($b['scheduled_date'])) . ", {$b['scheduled_time']}.";
    if ($b['technician_name']) {
        $text .= " Technician: {$b['technician_name']}.";
    }
    return $text;
}
