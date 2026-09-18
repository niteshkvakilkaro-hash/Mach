<?php
/**
 * Service quality: post-job feedback (ratings) and complaint / warranty tickets.
 *
 * Feedback: when a booking is completed a private link (random token) is created; the customer rates 1–5.
 *   4–5 stars → invited to review on Google. Low ratings → a complaint ticket is opened automatically.
 * Complaints: raised on the website, from low feedback, or by staff. Each has an SLA deadline, owner,
 *   history, and can schedule a free warranty revisit (a normal booking linked to the ticket).
 */

const FEEDBACK_TAGS_GOOD = ['on_time' => 'On time', 'polite' => 'Polite & professional', 'fixed' => 'Problem fixed', 'clean' => 'Clean work', 'fair_price' => 'Fair price'];
const FEEDBACK_TAGS_BAD  = ['late' => 'Came late', 'not_fixed' => 'Not fixed properly', 'overcharged' => 'Charged too much', 'rude' => 'Rude behaviour', 'messy' => 'Left a mess'];
const COMPLAINT_CATEGORIES = [
    'not_fixed' => 'Problem not fixed', 'repeat_issue' => 'Same problem again (warranty)', 'late' => 'Technician late / didn’t come',
    'overcharged' => 'Charged too much', 'behaviour' => 'Technician behaviour', 'damage' => 'Damage during service',
    'billing' => 'Bill / payment issue', 'other' => 'Other',
];
const COMPLAINT_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'revisit_scheduled' => 'Revisit scheduled', 'resolved' => 'Resolved', 'closed' => 'Closed', 'rejected' => 'Rejected'];
const COMPLAINT_STATUS_COLORS = ['open' => 'danger', 'in_progress' => 'warning', 'revisit_scheduled' => 'indigo', 'resolved' => 'success', 'closed' => 'secondary', 'rejected' => 'secondary'];
const COMPLAINT_OPEN = ['open', 'in_progress', 'revisit_scheduled'];

// ================================================================ feedback

/** Create (once) the feedback request for a completed booking and email the link. Returns the token. */
function feedback_request(int $bookingId, bool $sendEmail = true): ?string
{
    if (setting('feedback_enabled', '1') !== '1') return null;
    $existing = db_one('SELECT token, status FROM feedback WHERE booking_id = ?', [$bookingId]);
    if ($existing) return $existing['token'];
    $b = db_one("SELECT id, booking_number, customer_id, technician_id, status FROM bookings WHERE id = ? AND deleted_at IS NULL", [$bookingId]);
    if (!$b || $b['status'] !== 'completed') return null;
    $token = bin2hex(random_bytes(16));
    db_insert('feedback', ['booking_id' => $b['id'], 'customer_id' => $b['customer_id'], 'technician_id' => $b['technician_id'], 'token' => $token]);
    if ($sendEmail) mail_event('mail_event_feedback_request', $b['booking_number'], $token);
    return $token;
}

function feedback_url(string $token): string
{
    return url('feedback?t=' . $token);
}

/** Feedback + the job it belongs to, by public token. Only first names are exposed on the public page. */
function feedback_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    return db_one(
        "SELECT f.*, b.booking_number, b.scheduled_date, b.completed_at, s.name AS service_name,
                c.name AS customer_name, c.phone, c.email, t.name AS technician_name
         FROM feedback f JOIN bookings b ON b.id = f.booking_id JOIN customers c ON c.id = f.customer_id
         LEFT JOIN services s ON s.id = b.service_id LEFT JOIN technicians t ON t.id = f.technician_id
         WHERE f.token = ?",
        [$token]
    );
}

/** Save the customer's rating. Low ratings open a complaint and alert managers. */
function feedback_submit(array $fb, int $rating, array $tags, string $comment): array
{
    $validTags = array_keys(FEEDBACK_TAGS_GOOD + FEEDBACK_TAGS_BAD);
    $tags = array_values(array_intersect($tags, $validTags));
    $comment = clean_str($comment, 1000);
    $complaint = null;
    db_transaction(function () use ($fb, $rating, $tags, $comment, &$complaint) {
        $updated = db_query(
            "UPDATE feedback SET rating = ?, tags = ?, comment = ?, status = 'submitted', submitted_at = NOW(), ip_address = ?
             WHERE id = ? AND status = 'requested'",
            [$rating, $tags ? implode(',', $tags) : null, $comment ?: null, client_ip(), $fb['id']]
        )->rowCount();
        if (!$updated) return;                                         // already submitted (double click)
        if ($rating <= (int) setting('feedback_low_rating', '3')) {
            $category = match (true) {
                in_array('not_fixed', $tags, true)   => 'not_fixed',
                in_array('overcharged', $tags, true) => 'overcharged',
                in_array('late', $tags, true)        => 'late',
                in_array('rude', $tags, true)        => 'behaviour',
                default                              => 'other',
            };
            $labels = array_map(fn($t) => (FEEDBACK_TAGS_GOOD + FEEDBACK_TAGS_BAD)[$t], $tags);
            $complaint = complaint_create([
                'booking_id' => (int) $fb['booking_id'], 'customer_name' => $fb['customer_name'], 'phone' => $fb['phone'], 'email' => $fb['email'],
                'category' => $category, 'source' => 'feedback', 'priority' => $rating <= 2 ? 'urgent' : 'high',
                'description' => "Customer rated the job {$rating}/5." . ($labels ? ' Said: ' . implode(', ', $labels) . '.' : '') . ($comment ? "\n\n“{$comment}”" : ''),
            ], null);
            db_query('UPDATE feedback SET complaint_id = ? WHERE id = ?', [$complaint['id'], $fb['id']]);
        }
    });
    if ($rating <= (int) setting('feedback_low_rating', '3')) {
        notify_roles(['super_admin', 'admin', 'manager', 'support'], 'feedback.low', "⚠ {$rating}★ rating on {$fb['booking_number']}",
            ($fb['customer_name'] ?? '') . ' · ' . ($fb['technician_name'] ?? 'no technician') . ' — call the customer now', $complaint ? 'admin/complaints/view?number=' . $complaint['complaint_number'] : 'admin/feedback');
    }
    return ['complaint' => $complaint];
}

function feedback_stats(string $from, string $to, ?int $technicianId = null): array
{
    $tech = $technicianId ? ' AND f.technician_id = ' . (int) $technicianId : '';
    $row = db_one(
        "SELECT COUNT(*) AS requested, SUM(f.status = 'submitted') AS submitted, AVG(f.rating) AS avg_rating,
                SUM(f.rating = 5) AS five, SUM(f.rating >= 4) AS happy, SUM(f.rating <= 3) AS unhappy, SUM(f.google_clicked_at IS NOT NULL) AS google
         FROM feedback f WHERE f.requested_at BETWEEN ? AND ? $tech",
        ["$from 00:00:00", "$to 23:59:59"]
    );
    $row = array_map(fn($v) => $v === null ? 0 : $v, $row);
    $row['response_rate'] = pct($row['submitted'], $row['requested']);
    $row['happy_pct'] = pct($row['happy'], $row['submitted']);
    return $row;
}

/** Average rating per technician (all time) — used on technician cards and reports. */
function technician_ratings(): array
{
    static $cache = null;
    return $cache ??= array_column(db_all(
        "SELECT technician_id, ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS ratings,
                (SELECT COUNT(*) FROM complaints c WHERE c.technician_id = f.technician_id) AS complaints
         FROM feedback f WHERE status = 'submitted' AND technician_id IS NOT NULL GROUP BY technician_id"
    ), null, 'technician_id');
}

// ================================================================ complaints

/** SLA deadline: urgent tickets get the short SLA. */
function complaint_sla_due(string $priority): string
{
    $hours = (int) ($priority === 'urgent' ? setting('complaint_sla_hours_urgent', '2') : setting('complaint_sla_hours', '4'));
    return date('Y-m-d H:i:s', time() + max(1, $hours) * 3600);
}

/**
 * Open a complaint. Links the customer and the job (by booking number, or the latest completed job for the phone)
 * and flags warranty when the job is still under warranty.
 * @param array{booking_id?:int, booking_number?:string, customer_name:string, phone:string, email?:?string, category:string, description:?string, source:string, priority?:string} $d
 */
function complaint_create(array $d, ?int $userId): array
{
    $result = db_transaction(function () use ($d, $userId) {
        $customerId = db_value('SELECT id FROM customers WHERE phone = ? AND deleted_at IS NULL', [$d['phone']]) ?: null;
        $booking = null;
        if (!empty($d['booking_id'])) {
            $booking = db_one('SELECT id, customer_id, technician_id, warranty_until FROM bookings WHERE id = ?', [$d['booking_id']]);
        } elseif (!empty($d['booking_number'])) {
            $booking = db_one('SELECT b.id, b.customer_id, b.technician_id, b.warranty_until FROM bookings b JOIN customers c ON c.id = b.customer_id
                               WHERE b.booking_number = ? AND c.phone = ? AND b.deleted_at IS NULL', [$d['booking_number'], $d['phone']]);
        }
        if (!$booking && $customerId) {
            $booking = db_one("SELECT id, customer_id, technician_id, warranty_until FROM bookings WHERE customer_id = ? AND status = 'completed' AND deleted_at IS NULL
                               ORDER BY completed_at DESC LIMIT 1", [$customerId]);
        }
        $isWarranty = $booking && $booking['warranty_until'] && $booking['warranty_until'] >= date('Y-m-d');
        $priority = $d['priority'] ?? ($isWarranty || in_array($d['category'], ['damage', 'behaviour'], true) ? 'high' : 'normal');
        $number = next_number('CMP');
        $id = db_insert('complaints', [
            'complaint_number' => $number, 'customer_id' => $booking['customer_id'] ?? $customerId, 'booking_id' => $booking['id'] ?? null,
            'technician_id' => $booking['technician_id'] ?? null, 'customer_name' => $d['customer_name'], 'phone' => $d['phone'], 'email' => $d['email'] ?? null,
            'category' => $d['category'], 'description' => $d['description'] ?? null, 'source' => $d['source'], 'priority' => $priority,
            'is_warranty' => $isWarranty ? 1 : 0, 'sla_due_at' => complaint_sla_due($priority), 'created_by' => $userId, 'ip_address' => client_ip(),
        ]);
        db_insert('complaint_updates', ['complaint_id' => $id, 'user_id' => $userId, 'type' => 'created', 'new_status' => 'open',
            'note' => 'Complaint registered via ' . $d['source'] . ($isWarranty ? ' — job is under warranty' : '')]);
        if ($userId) audit_log($userId, 'created', 'complaints', $id, "Created complaint $number");
        return ['id' => $id, 'complaint_number' => $number, 'is_warranty' => $isWarranty, 'priority' => $priority];
    });

    $label = COMPLAINT_CATEGORIES[$d['category']] ?? $d['category'];
    try {
        notify_roles(['super_admin', 'admin', 'manager', 'support'], 'complaint.new', "Complaint {$result['complaint_number']}" . ($result['is_warranty'] ? ' (warranty)' : ''),
            "{$d['customer_name']} · $label", 'admin/complaints/view?number=' . $result['complaint_number']);
    } catch (Throwable $e) {
        app_log('warning', 'Complaint notification failed: ' . $e->getMessage());
    }
    mail_event('mail_event_complaint', $result['complaint_number']);
    return $result;
}

function complaint_find(string $number): ?array
{
    return db_one(
        "SELECT c.*, b.booking_number, b.scheduled_date AS job_date, b.warranty_until, b.final_amount, s.name AS service_name,
                t.name AS technician_name, t.phone AS technician_phone, u.name AS assigned_name, cu.customer_number,
                rb.booking_number AS revisit_number, rb.scheduled_date AS revisit_date, rbs.label AS revisit_status,
                f.rating AS feedback_rating, f.comment AS feedback_comment
         FROM complaints c
         LEFT JOIN bookings b ON b.id = c.booking_id
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN technicians t ON t.id = c.technician_id
         LEFT JOIN users u ON u.id = c.assigned_to
         LEFT JOIN customers cu ON cu.id = c.customer_id
         LEFT JOIN bookings rb ON rb.id = c.revisit_booking_id
         LEFT JOIN booking_statuses rbs ON rbs.slug = rb.status
         LEFT JOIN feedback f ON f.complaint_id = c.id
         WHERE c.complaint_number = ?",
        [$number]
    );
}

function complaint_add_update(array $c, ?int $userId, string $type, string $note, ?string $newStatus = null): void
{
    db_insert('complaint_updates', ['complaint_id' => $c['id'], 'user_id' => $userId, 'type' => $type,
        'old_status' => $newStatus ? $c['status'] : null, 'new_status' => $newStatus, 'note' => $note !== '' ? $note : null]);
    if ($userId && !$c['first_response_at']) {
        db_query('UPDATE complaints SET first_response_at = NOW() WHERE id = ? AND first_response_at IS NULL', [$c['id']]);
    }
}

function complaint_change_status(array $c, string $status, string $note, int $userId): void
{
    if (!isset(COMPLAINT_STATUSES[$status])) throw new InvalidArgumentException('Unknown status');
    if ($status === $c['status']) return;
    if (in_array($status, ['resolved', 'rejected'], true) && mb_strlen($note) < 5) {
        throw new InvalidArgumentException('Write what was done (resolution) before marking it ' . strtolower(COMPLAINT_STATUSES[$status]));
    }
    db_transaction(function () use ($c, $status, $note, $userId) {
        db_query(
            'UPDATE complaints SET status = ?, resolution = IF(? IN (\'resolved\', \'rejected\'), ?, resolution),
                    resolved_at = IF(? = \'resolved\', NOW(), resolved_at), closed_at = IF(? IN (\'closed\', \'rejected\'), NOW(), closed_at) WHERE id = ?',
            [$status, $status, $note, $status, $status, $c['id']]
        );
        complaint_add_update($c, $userId, 'status', $note, $status);
        audit_log($userId, 'status_changed', 'complaints', (int) $c['id'], "{$c['complaint_number']}: {$c['status']} → $status");
    });
    if ($status === 'resolved') mail_event('mail_event_complaint_resolved', $c['complaint_number']);
}

function complaint_assign(array $c, ?int $assignee, int $userId): void
{
    if ($assignee && !in_array($assignee, array_map('intval', array_column(lead_staff_options(), 'id')), true)) {
        throw new InvalidArgumentException('Choose an active staff member');
    }
    db_query('UPDATE complaints SET assigned_to = ?, status = IF(status = \'open\' AND ? IS NOT NULL, \'in_progress\', status) WHERE id = ?', [$assignee, $assignee, $c['id']]);
    $name = $assignee ? db_value('SELECT name FROM users WHERE id = ?', [$assignee]) : 'nobody';
    complaint_add_update($c, $userId, 'assign', "Assigned to $name");
    audit_log($userId, 'assigned', 'complaints', (int) $c['id'], "{$c['complaint_number']} → $name");
    if ($assignee && $assignee !== $userId) {
        notify_user($assignee, 'complaint.assigned', "Complaint assigned to you: {$c['complaint_number']}", $c['customer_name'] . ' · ' . (COMPLAINT_CATEGORIES[$c['category']] ?? ''), 'admin/complaints/view?number=' . $c['complaint_number']);
    }
}

/** Book a revisit for a complaint (free when under warranty) and link it. */
function complaint_schedule_revisit(array $c, array $d, int $userId): array
{
    $orig = $c['booking_id'] ? db_one('SELECT * FROM bookings WHERE id = ?', [$c['booking_id']]) : null;
    $data = [
        'service_id' => $d['service_id'] ?? ($orig['service_id'] ?? null), 'scheduled_date' => $d['scheduled_date'], 'scheduled_time' => $d['scheduled_time'],
        'address' => $d['address'] ?: ($orig['address'] ?? ''), 'city' => $orig['city'] ?? null, 'pincode' => $orig['pincode'] ?? null, 'location_id' => $orig['location_id'] ?? null,
        'technician_id' => $d['technician_id'], 'estimated_amount' => $c['is_warranty'] ? 0 : null,
        'notes' => ($c['is_warranty'] ? 'FREE WARRANTY REVISIT' : 'Complaint revisit') . " for {$c['complaint_number']}: " . (COMPLAINT_CATEGORIES[$c['category']] ?? '') . ($c['description'] ? "\n" . mb_substr($c['description'], 0, 500) : ''),
    ];
    $booking = booking_create(null, ['name' => $c['customer_name'], 'phone' => $c['phone'], 'email' => $c['email']], $data, $userId);
    $bookingId = (int) db_value('SELECT id FROM bookings WHERE booking_number = ?', [$booking['booking_number']]);
    db_query("UPDATE complaints SET revisit_booking_id = ?, status = 'revisit_scheduled', customer_id = COALESCE(customer_id, ?) WHERE id = ?", [$bookingId, $booking['customer_id'], $c['id']]);
    complaint_add_update($c, $userId, 'revisit', "Revisit booked: {$booking['booking_number']} on " . date('d M', strtotime($d['scheduled_date'])) . ", {$d['scheduled_time']}" . ($c['is_warranty'] ? ' (free — warranty)' : ''), 'revisit_scheduled');
    return $booking;
}

/** Alert about complaints that crossed their SLA without being resolved. Safe to call often. */
function complaints_notify_overdue(): int
{
    $rows = db_all(
        "SELECT id, complaint_number, customer_name, assigned_to FROM complaints
         WHERE status IN ('open', 'in_progress') AND sla_due_at < NOW() AND sla_notified_at IS NULL LIMIT 100"
    );
    foreach ($rows as $c) {
        $title = "Complaint overdue: {$c['complaint_number']}";
        $link = 'admin/complaints/view?number=' . $c['complaint_number'];
        notify_roles(['super_admin', 'admin', 'manager'], 'complaint.overdue', $title, "{$c['customer_name']} — SLA crossed", $link);
        if ($c['assigned_to']) notify_user((int) $c['assigned_to'], 'complaint.overdue', $title, "{$c['customer_name']} — SLA crossed", $link);
        db_query('UPDATE complaints SET sla_notified_at = NOW() WHERE id = ?', [$c['id']]);
    }
    return count($rows);
}

function complaint_open_count(): array
{
    return array_map('intval', db_one(
        "SELECT SUM(status IN ('open','in_progress','revisit_scheduled')) AS open_total,
                SUM(status IN ('open','in_progress') AND sla_due_at < NOW()) AS overdue FROM complaints"
    ) ?: ['open_total' => 0, 'overdue' => 0]);
}

// ================================================================ emails

function mail_event_feedback_request(string $bookingNumber, string $token): void
{
    $b = booking_find($bookingNumber);
    if (!$b || empty($b['email'])) return;
    [$html, $text] = mail_template(
        'How was your service?',
        'Hi ' . strtok($b['customer_name'], ' ') . ', thank you for choosing ' . setting('business_name') . '. It takes 10 seconds to rate your '
            . ($b['service_name'] ?: 'service') . ($b['technician_name'] ? ' by ' . strtok($b['technician_name'], ' ') : '') . ' — it helps us keep our service great.',
        ['Booking' => $b['booking_number'], 'Visit' => date('d M Y', strtotime($b['scheduled_date']))],
        ['Rate your service ★★★★★', feedback_url($token)],
        'Not happy with something? Tell us in the same form and a manager will call you.'
    );
    mail_queue($b['email'], $b['customer_name'], 'How was your ' . ($b['service_name'] ?: 'service') . '? Rate us in 10 seconds', $html, $text, 'feedback.request', $b['booking_number']);
}

function mail_event_complaint(string $number): void
{
    $c = complaint_find($number);
    if (!$c) return;
    $rows = ['Complaint no.' => $c['complaint_number'], 'Issue' => COMPLAINT_CATEGORIES[$c['category']] ?? $c['category'],
             'Booking' => $c['booking_number'], 'Warranty' => $c['is_warranty'] ? 'Yes — revisit is free' : null];
    // Customer acknowledgement
    if ($c['email'] && $c['source'] !== 'feedback') {
        [$html, $text] = mail_template('We’ve registered your complaint',
            'Hi ' . strtok($c['customer_name'] ?: 'there', ' ') . ', we’re sorry for the trouble. Our service manager will call you within '
                . (int) ($c['priority'] === 'urgent' ? setting('complaint_sla_hours_urgent', '2') : setting('complaint_sla_hours', '4')) . ' working hours.',
            $rows, ['WhatsApp us about this complaint', whatsapp_link('Hi, about my complaint ' . $c['complaint_number'] . '.')]);
        mail_queue($c['email'], $c['customer_name'], 'Complaint registered — ' . $c['complaint_number'], $html, $text, 'complaint.customer', $c['complaint_number']);
    }
    // Team alert
    if (setting('notify_email') === '1') {
        [$html, $text] = mail_template("Complaint {$c['complaint_number']}" . ($c['is_warranty'] ? ' — WARRANTY' : ''),
            'A customer has raised a complaint. Call them before the SLA runs out.',
            $rows + ['Customer' => $c['customer_name'], 'Phone' => $c['phone'], 'Technician' => $c['technician_name'], 'Priority' => ucfirst($c['priority']),
                     'Respond by' => $c['sla_due_at'] ? date('d M, h:i A', strtotime($c['sla_due_at'])) : null, 'Details' => $c['description']],
            ['Open complaint', url('admin/complaints/view?number=' . $c['complaint_number'])]);
        foreach (array_filter(array_map('trim', explode(',', setting('notify_email_to')))) as $to) {
            mail_queue($to, '', "⚠ Complaint {$c['complaint_number']} — " . (COMPLAINT_CATEGORIES[$c['category']] ?? '') . " ({$c['customer_name']})", $html, $text, 'complaint.new', $c['complaint_number']);
        }
    }
}

function mail_event_complaint_resolved(string $number): void
{
    $c = complaint_find($number);
    if (!$c || !$c['email']) return;
    [$html, $text] = mail_template('Your complaint is resolved',
        'Hi ' . strtok($c['customer_name'] ?: 'there', ' ') . ', we’ve closed complaint ' . $c['complaint_number'] . '. If anything is still not right, just reply to this email or WhatsApp us and we’ll reopen it.',
        ['Complaint no.' => $c['complaint_number'], 'What we did' => $c['resolution']],
        ['Still an issue? WhatsApp us', whatsapp_link('Hi, complaint ' . $c['complaint_number'] . ' is still not resolved.')]);
    mail_queue($c['email'], $c['customer_name'], 'Resolved — ' . $c['complaint_number'], $html, $text, 'complaint.resolved', $c['complaint_number']);
}
