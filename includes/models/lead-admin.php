<?php
/**
 * Lead management for the admin CRM: search, detail, status, assignment, notes,
 * follow-ups, timeline, manual creation and soft delete.
 * Every mutating function writes history + audit log; callers check permissions first.
 */

const FOLLOWUP_TYPES = [
    'call' => 'Call', 'whatsapp' => 'WhatsApp', 'visit' => 'Visit',
    'payment_reminder' => 'Payment Reminder', 'service_reminder' => 'Service Reminder', 'other' => 'Other',
];
const LEAD_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
/** Closing a lead with these statuses requires a reason. */
const LEAD_LOST_STATUSES = ['cancelled', 'not_interested', 'invalid'];
/** Progress tracker on the lead page: stage => status that marks it reached. */
const LEAD_STAGES = [
    'new' => 'Lead created', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'scheduled' => 'Scheduled',
    'technician_assigned' => 'Technician assigned', 'visit_completed' => 'Visit completed',
    'payment_pending' => 'Payment', 'completed' => 'Completed',
];

// ---------------------------------------------------------------- lookups

function lead_statuses(): array
{
    static $cache = null;
    return $cache ??= array_column(db_all('SELECT * FROM lead_statuses ORDER BY sort_order'), null, 'slug');
}

/** Staff who can own leads (everyone active except technicians). */
function lead_staff_options(): array
{
    static $cache = null;
    return $cache ??= db_all(
        "SELECT u.id, u.name, r.name AS role_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.status = 'active' AND u.deleted_at IS NULL AND r.slug <> 'technician' ORDER BY u.name"
    );
}

function lead_technician_options(): array
{
    static $cache = null;
    return $cache ??= db_all(
        "SELECT id, name, specialization, status FROM technicians
         WHERE deleted_at IS NULL AND status <> 'inactive' ORDER BY status = 'on_leave', name"
    );
}

// ---------------------------------------------------------------- search

/** Normalise list filters from the query string. */
function lead_filters(array $q): array
{
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
    return [
        'q'          => clean_str($q['q'] ?? '', 100),
        'status'     => isset(lead_statuses()[$q['status'] ?? '']) ? $q['status'] : (($q['status'] ?? '') === 'open' ? 'open' : ''),
        'service'    => (int) ($q['service'] ?? 0) ?: '',
        'source'     => isset(LEAD_SOURCES[$q['source'] ?? '']) ? $q['source'] : '',
        'assigned'   => ($q['assigned'] ?? '') === 'none' ? 'none' : ((int) ($q['assigned'] ?? 0) ?: ''),
        'technician' => ($q['technician'] ?? '') === 'none' ? 'none' : ((int) ($q['technician'] ?? 0) ?: ''),
        'priority'   => isset(LEAD_PRIORITIES[$q['priority'] ?? '']) ? $q['priority'] : '',
        'from'       => $date($q['from'] ?? ''),
        'to'         => $date($q['to'] ?? ''),
    ];
}

/** WHERE clause for the filters (+ row-level visibility). $skipStatus lets tabs count per status. */
function lead_where(array $f, bool $skipStatus = false): array
{
    [$vis, $params] = lead_visibility('l');
    $sql = 'l.deleted_at IS NULL' . $vis;

    if ($f['q'] !== '') {
        $digits = preg_replace('/\D+/', '', $f['q']);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $sql .= ' AND (l.customer_name LIKE ? OR l.email LIKE ? OR l.lead_number LIKE ?' . (strlen($digits) >= 4 ? ' OR l.phone LIKE ?' : '') . ')';
        array_push($params, $like, $like, $like);
        if (strlen($digits) >= 4) {
            $params[] = '%' . substr($digits, -10) . '%';
        }
    }
    if (!$skipStatus && $f['status'] === 'open') {
        $sql .= ' AND l.status IN (SELECT slug FROM lead_statuses WHERE is_closed = 0)';
    } elseif (!$skipStatus && $f['status'] !== '') {
        $sql .= ' AND l.status = ?';
        $params[] = $f['status'];
    }
    foreach (['service' => 'l.service_id', 'source' => 'l.source', 'priority' => 'l.priority'] as $key => $col) {
        if ($f[$key] !== '') {
            $sql .= " AND $col = ?";
            $params[] = $f[$key];
        }
    }
    if ($f['assigned'] === 'none') {
        $sql .= ' AND l.assigned_to IS NULL';
    } elseif ($f['assigned'] !== '') {
        $sql .= ' AND l.assigned_to = ?';
        $params[] = $f['assigned'];
    }
    if ($f['technician'] === 'none') {
        $sql .= ' AND l.technician_id IS NULL';
    } elseif ($f['technician'] !== '') {
        $sql .= ' AND l.technician_id = ?';
        $params[] = $f['technician'];
    }
    if ($f['from'] !== '') {
        $sql .= ' AND l.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $sql .= ' AND l.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    return [$sql, $params];
}

/** One page of leads + total count. Joins are fixed so there is no N+1. */
function lead_search(array $f, int $page, int $perPage = 25): array
{
    [$where, $params] = lead_where($f);
    $total = (int) db_value("SELECT COUNT(*) FROM leads l WHERE $where", $params);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = db_all(
        "SELECT l.id, l.lead_number, l.customer_name, l.phone, l.email, l.city, l.source, l.form_type, l.status,
                l.priority, l.enquiry_count, l.is_repeat_customer, l.created_at, l.preferred_date, l.preferred_time,
                s.name AS service_name, loc.name AS area_name, u.name AS assigned_name, t.name AS technician_name,
                st.label AS status_label, st.color AS status_color
         FROM leads l
         JOIN lead_statuses st ON st.slug = l.status
         LEFT JOIN services s ON s.id = l.service_id
         LEFT JOIN locations loc ON loc.id = l.location_id
         LEFT JOIN users u ON u.id = l.assigned_to
         LEFT JOIN technicians t ON t.id = l.technician_id
         WHERE $where
         ORDER BY l.id DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
    return ['total' => $total, 'rows' => $rows];
}

/** Lead counts per status for the tabs, respecting all other filters. */
function lead_status_counts(array $f): array
{
    [$where, $params] = lead_where($f, true);
    return array_map('intval', array_column(db_all("SELECT l.status, COUNT(*) AS n FROM leads l WHERE $where GROUP BY l.status", $params), 'n', 'status'));
}

// ---------------------------------------------------------------- single lead

/** Full lead row by public number, or null if missing / not visible to the user. */
function lead_find(string $number): ?array
{
    [$vis, $params] = lead_visibility('l');
    return db_one(
        "SELECT l.*, s.name AS service_name, s.slug AS service_slug, c.name AS category_name, loc.name AS area_name,
                u.name AS assigned_name, t.name AS technician_name, t.phone AS technician_phone,
                st.label AS status_label, st.color AS status_color, st.is_closed, st.is_won,
                p.lead_number AS parent_number, cu.customer_number, cr.name AS created_by_name
         FROM leads l
         JOIN lead_statuses st ON st.slug = l.status
         LEFT JOIN services s ON s.id = l.service_id
         LEFT JOIN service_categories c ON c.id = l.category_id
         LEFT JOIN locations loc ON loc.id = l.location_id
         LEFT JOIN users u ON u.id = l.assigned_to
         LEFT JOIN technicians t ON t.id = l.technician_id
         LEFT JOIN leads p ON p.id = l.parent_lead_id
         LEFT JOIN customers cu ON cu.id = l.customer_id
         LEFT JOIN users cr ON cr.id = l.created_by
         WHERE l.lead_number = ? AND l.deleted_at IS NULL $vis",
        array_merge([$number], $params)
    );
}

/** Load a lead for an API call or fail with a JSON 404. */
function lead_or_404(string $number): array
{
    $lead = lead_find(clean_str($number, 20));
    if (!$lead) {
        json_response(false, 'Lead not found or you do not have access to it.', [], 404);
    }
    return $lead;
}

function lead_notes(int $leadId): array
{
    return db_all(
        'SELECT n.id, n.note, n.created_at, u.name AS user_name FROM lead_notes n
         LEFT JOIN users u ON u.id = n.user_id WHERE n.lead_id = ? ORDER BY n.id DESC',
        [$leadId]
    );
}

function lead_followups(int $leadId): array
{
    return db_all(
        'SELECT f.*, u.name AS assignee, cb.name AS completed_by_name FROM lead_followups f
         LEFT JOIN users u ON u.id = f.assigned_to LEFT JOIN users cb ON cb.id = f.completed_by
         WHERE f.lead_id = ? ORDER BY f.status = \'pending\' DESC, f.followup_date, f.followup_time',
        [$leadId]
    );
}

function lead_enquiries(int $leadId): array
{
    return db_all(
        'SELECT e.*, s.name AS service_name FROM lead_enquiries e LEFT JOIN services s ON s.id = e.service_id
         WHERE e.lead_id = ? ORDER BY e.id DESC',
        [$leadId]
    );
}

/** Other leads from the same phone number (repeat-customer history). */
function lead_related(array $lead): array
{
    [$vis, $params] = lead_visibility('l');
    return db_all(
        "SELECT l.lead_number, l.created_at, s.name AS service_name, st.label AS status_label, st.color AS status_color
         FROM leads l JOIN lead_statuses st ON st.slug = l.status LEFT JOIN services s ON s.id = l.service_id
         WHERE l.phone = ? AND l.id <> ? AND l.deleted_at IS NULL $vis ORDER BY l.id DESC LIMIT 10",
        array_merge([$lead['phone'], $lead['id']], $params)
    );
}

function lead_bookings(int $leadId): array
{
    return db_all(
        'SELECT b.booking_number, b.scheduled_date, b.scheduled_time, b.final_amount, b.estimated_amount, b.amount_paid,
                b.payment_status, bs.label AS status_label, bs.color AS status_color, t.name AS technician_name
         FROM bookings b JOIN booking_statuses bs ON bs.slug = b.status LEFT JOIN technicians t ON t.id = b.technician_id
         WHERE b.lead_id = ? AND b.deleted_at IS NULL ORDER BY b.id DESC',
        [$leadId]
    );
}

function lead_payments(int $leadId): array
{
    return db_all(
        'SELECT p.payment_number, p.amount, p.payment_method, p.status, p.payment_date, b.booking_number
         FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.lead_id = ? ORDER BY p.id DESC',
        [$leadId]
    );
}

/** Everything that happened to a lead, newest first. */
function lead_timeline(int $leadId): array
{
    $statuses = lead_statuses();
    $events = [];
    foreach (db_all('SELECT h.*, u.name AS user_name FROM lead_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.lead_id = ?', [$leadId]) as $h) {
        $events[] = [
            'at' => $h['created_at'], 'icon' => $h['old_status'] === null ? 'bi-plus-circle' : 'bi-arrow-right-circle',
            'color' => $statuses[$h['new_status']]['color'] ?? 'secondary',
            'title' => $h['old_status'] === null ? 'Lead created' : 'Status: ' . ($statuses[$h['old_status']]['label'] ?? $h['old_status']) . ' → ' . ($statuses[$h['new_status']]['label'] ?? $h['new_status']),
            'text' => $h['note'], 'by' => $h['user_name'] ?? 'System',
        ];
    }
    foreach (db_all(
        'SELECT a.*, u.name AS to_name, t.name AS tech_name, b.name AS by_name FROM lead_assignments a
         LEFT JOIN users u ON u.id = a.assigned_to LEFT JOIN technicians t ON t.id = a.technician_id LEFT JOIN users b ON b.id = a.assigned_by
         WHERE a.lead_id = ?', [$leadId]) as $a) {
        $who = $a['assignment_type'] === 'technician' ? ($a['tech_name'] ?? 'nobody') : ($a['to_name'] ?? 'nobody');
        $events[] = [
            'at' => $a['created_at'], 'icon' => $a['assignment_type'] === 'technician' ? 'bi-person-gear' : 'bi-person-check',
            'color' => 'indigo', 'title' => ($a['assigned_to'] || $a['technician_id'] ? 'Assigned to ' . $who : 'Unassigned') . ' (' . $a['assignment_type'] . ')',
            'text' => $a['note'], 'by' => $a['by_name'] ?? 'Auto-assignment',
        ];
    }
    foreach (db_all('SELECT n.*, u.name AS user_name FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.lead_id = ?', [$leadId]) as $n) {
        $events[] = ['at' => $n['created_at'], 'icon' => 'bi-sticky', 'color' => 'secondary', 'title' => 'Note added', 'text' => $n['note'], 'by' => $n['user_name'] ?? 'System'];
    }
    foreach (db_all('SELECT f.*, u.name AS by_name, c.name AS done_name FROM lead_followups f LEFT JOIN users u ON u.id = f.created_by LEFT JOIN users c ON c.id = f.completed_by WHERE f.lead_id = ?', [$leadId]) as $f) {
        $when = date('d M', strtotime($f['followup_date'])) . ($f['followup_time'] ? ' ' . date('h:i A', strtotime($f['followup_time'])) : '');
        $events[] = ['at' => $f['created_at'], 'icon' => 'bi-alarm', 'color' => 'warning', 'title' => (FOLLOWUP_TYPES[$f['type']] ?? $f['type']) . " follow-up scheduled for $when", 'text' => $f['note'], 'by' => $f['by_name'] ?? 'System'];
        if ($f['status'] !== 'pending' && $f['completed_at']) {
            $events[] = ['at' => $f['completed_at'], 'icon' => $f['status'] === 'completed' ? 'bi-check2-circle' : 'bi-x-circle', 'color' => $f['status'] === 'completed' ? 'success' : 'secondary',
                'title' => 'Follow-up ' . $f['status'], 'text' => $f['outcome'], 'by' => $f['done_name'] ?? 'System'];
        }
    }
    foreach (db_all('SELECT * FROM lead_enquiries WHERE lead_id = ? ORDER BY id LIMIT 18446744073709551615 OFFSET 1', [$leadId]) as $e) {
        $events[] = ['at' => $e['created_at'], 'icon' => 'bi-arrow-repeat', 'color' => 'orange', 'title' => 'Repeat enquiry (' . $e['form_type'] . ')', 'text' => $e['message'], 'by' => 'Customer'];
    }
    usort($events, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $events;
}

/** Which progress stages the lead has reached (history + current status). */
function lead_stage_progress(array $lead): array
{
    $statuses = lead_statuses();
    $seen = array_column(db_all('SELECT DISTINCT new_status FROM lead_status_history WHERE lead_id = ?', [$lead['id']]), 'new_status');
    $seen[] = $lead['status'];
    $maxOrder = 0;
    foreach ($seen as $s) {
        if (!in_array($s, LEAD_LOST_STATUSES, true)) {
            $maxOrder = max($maxOrder, (int) ($statuses[$s]['sort_order'] ?? 0));
        }
    }
    $out = [];
    foreach (LEAD_STAGES as $slug => $label) {
        $order = (int) ($statuses[$slug]['sort_order'] ?? 0);
        $out[] = ['label' => $label, 'done' => in_array($slug, $seen, true) || $order < $maxOrder, 'current' => $lead['status'] === $slug];
    }
    return $out;
}

// ---------------------------------------------------------------- mutations

function lead_change_status(array $lead, string $status, string $note, int $userId): void
{
    $statuses = lead_statuses();
    if (!isset($statuses[$status])) {
        throw new InvalidArgumentException('Unknown status');
    }
    if ($status === $lead['status']) {
        return;
    }
    db_transaction(function () use ($lead, $status, $note, $userId, $statuses) {
        db_query(
            'UPDATE leads SET status = ?, lost_reason = IF(?, ?, lost_reason),
                    converted_at = IF(? AND converted_at IS NULL, NOW(), converted_at) WHERE id = ?',
            [$status, in_array($status, LEAD_LOST_STATUSES, true) ? 1 : 0, $note ?: null, (int) $statuses[$status]['is_won'], $lead['id']]
        );
        db_insert('lead_status_history', [
            'lead_id' => $lead['id'], 'old_status' => $lead['status'], 'new_status' => $status,
            'changed_by' => $userId, 'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);
        audit_log($userId, 'status_changed', 'leads', (int) $lead['id'],
            "{$lead['lead_number']}: {$lead['status']} → $status", ['status' => $lead['status']], ['status' => $status]);
    });
}

/**
 * Assign a lead to a staff user ($type sales|support) or a technician ($type technician).
 * $targetId null = unassign. History is always appended, never overwritten.
 */
function lead_assign(array $lead, string $type, ?int $targetId, string $note, int $userId): void
{
    if ($type === 'technician') {
        if ($targetId !== null && !db_value("SELECT 1 FROM technicians WHERE id = ? AND deleted_at IS NULL AND status <> 'inactive'", [$targetId])) {
            throw new InvalidArgumentException('Technician not available');
        }
        if ((int) $lead['technician_id'] === (int) $targetId) {
            return;
        }
    } else {
        if ($targetId !== null && !in_array($targetId, array_map('intval', array_column(lead_staff_options(), 'id')), true)) {
            throw new InvalidArgumentException('Staff member not available');
        }
        if ((int) $lead['assigned_to'] === (int) $targetId) {
            return;
        }
    }

    db_transaction(function () use ($lead, $type, $targetId, $note, $userId) {
        $col = $type === 'technician' ? 'technician_id' : 'assigned_to';
        db_query("UPDATE leads SET $col = ? WHERE id = ?", [$targetId, $lead['id']]);
        db_insert('lead_assignments', [
            'lead_id' => $lead['id'], 'assignment_type' => $type,
            'assigned_to' => $type === 'technician' ? null : $targetId,
            'technician_id' => $type === 'technician' ? $targetId : null,
            'assigned_by' => $userId, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
        ]);
        audit_log($userId, 'assigned', 'leads', (int) $lead['id'], "{$lead['lead_number']}: $type → " . ($targetId ?? 'none'),
            [$col => $lead[$col]], [$col => $targetId]);

        // Assigning a technician moves early-stage leads forward automatically.
        if ($type === 'technician' && $targetId && in_array($lead['status'], ['new', 'contacted', 'follow_up', 'scheduled'], true)) {
            lead_change_status($lead, 'technician_assigned', 'Technician assigned', $userId);
        }
    });

    if ($type !== 'technician' && $targetId && $targetId !== $userId) {
        notify_user($targetId, 'lead.assigned', "Lead assigned to you: {$lead['lead_number']}",
            trim(($lead['customer_name'] ?: $lead['phone']) . ' · ' . ($lead['service_name'] ?? '')), 'admin/leads/view?number=' . $lead['lead_number']);
    }
}

function lead_add_note(array $lead, string $note, int $userId): void
{
    db_insert('lead_notes', ['lead_id' => $lead['id'], 'user_id' => $userId, 'note' => $note]);
    audit_log($userId, 'note_added', 'leads', (int) $lead['id'], "{$lead['lead_number']}: note added");
}

function followup_create(array $lead, array $data, int $userId): int
{
    $id = db_insert('lead_followups', [
        'lead_id' => $lead['id'], 'assigned_to' => $data['assigned_to'], 'created_by' => $userId,
        'followup_date' => $data['date'], 'followup_time' => $data['time'], 'type' => $data['type'], 'note' => $data['note'],
    ]);
    audit_log($userId, 'followup_created', 'leads', (int) $lead['id'], "{$lead['lead_number']}: {$data['type']} follow-up on {$data['date']}");
    if ($data['assigned_to'] && $data['assigned_to'] !== $userId) {
        notify_user($data['assigned_to'], 'followup.assigned', "Follow-up for {$lead['lead_number']}",
            FOLLOWUP_TYPES[$data['type']] . ' on ' . date('d M', strtotime($data['date'])), 'admin/leads/view?number=' . $lead['lead_number']);
    }
    return $id;
}

function followup_close(array $lead, int $followupId, string $status, string $outcome, int $userId): bool
{
    $ok = db_query(
        "UPDATE lead_followups SET status = ?, outcome = ?, completed_at = NOW(), completed_by = ?
         WHERE id = ? AND lead_id = ? AND status = 'pending'",
        [$status, $outcome !== '' ? mb_substr($outcome, 0, 500) : null, $userId, $followupId, $lead['id']]
    )->rowCount() > 0;
    if ($ok) {
        audit_log($userId, 'followup_' . $status, 'leads', (int) $lead['id'], "{$lead['lead_number']}: follow-up #$followupId $status");
    }
    return $ok;
}

function lead_soft_delete(array $lead, int $userId): void
{
    db_query('UPDATE leads SET deleted_at = NOW() WHERE id = ?', [$lead['id']]);
    audit_log($userId, 'deleted', 'leads', (int) $lead['id'], "Deleted lead {$lead['lead_number']}",
        ['lead_number' => $lead['lead_number'], 'customer_name' => $lead['customer_name'], 'phone' => $lead['phone'], 'status' => $lead['status']]);
}

/**
 * Validate the admin create/edit form. Unlike the public form, past dates are allowed
 * and consent is not asked (staff are entering data on the customer's behalf).
 */
function lead_admin_validate(array $in): array
{
    $errors = [];
    $name = clean_str($in['customer_name'] ?? '', 100);
    if (mb_strlen($name) < 2) $errors['customer_name'] = 'Enter the customer name.';
    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    $email = clean_str($in['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';

    $services = array_column(get_services(), null, 'id');
    $serviceId = (int) ($in['service_id'] ?? 0);
    if ($serviceId && !isset($services[$serviceId])) $errors['service_id'] = 'Choose a valid service.';

    $date = clean_str($in['preferred_date'] ?? '', 10);
    if ($date !== '' && !DateTime::createFromFormat('!Y-m-d', $date)) $errors['preferred_date'] = 'Invalid date.';
    $pincode = clean_str($in['pincode'] ?? '', 10);
    if ($pincode !== '' && !preg_match('/^[1-9]\d{5}$/', $pincode)) $errors['pincode'] = 'Enter a valid 6-digit pincode.';

    $locationId = (int) ($in['location_id'] ?? 0);
    $validArea = false;
    foreach (get_locations() as $c) foreach ($c['areas'] as $a) if ((int) $a['id'] === $locationId) $validArea = true;

    $source = isset(LEAD_SOURCES[$in['source'] ?? '']) ? $in['source'] : 'phone';
    $priority = isset(LEAD_PRIORITIES[$in['priority'] ?? '']) ? $in['priority'] : 'normal';

    return [[
        'customer_name'  => $name,
        'phone'          => $phone,
        'email'          => $email ?: null,
        'service_id'     => $serviceId ?: null,
        'category_id'    => $serviceId && isset($services[$serviceId]) ? (int) $services[$serviceId]['category_id'] : null,
        'appliance'      => clean_str($in['appliance'] ?? '', 100) ?: null,
        'brand'          => clean_str($in['brand'] ?? '', 60) ?: null,
        'problem_type'   => clean_str($in['problem_type'] ?? '', 150) ?: null,
        'description'    => clean_str($in['description'] ?? '', 2000) ?: null,
        'address'        => clean_str($in['address'] ?? '', 500) ?: null,
        'city'           => clean_str($in['city'] ?? '', 80) ?: null,
        'pincode'        => $pincode ?: null,
        'location_id'    => $validArea ? $locationId : null,
        'preferred_date' => $date ?: null,
        'preferred_time' => clean_str($in['preferred_time'] ?? '', 40) ?: null,
        'source'         => $source,
        'priority'       => $priority,
    ], $errors];
}

/** Open leads for a phone number (used to warn before creating a duplicate). */
function lead_open_for_phone(string $phone, ?int $excludeId = null): array
{
    [$vis, $params] = lead_visibility('l');
    return db_all(
        "SELECT l.id, l.lead_number, l.customer_name, l.created_at, s.name AS service_name, st.label AS status_label, st.color AS status_color
         FROM leads l JOIN lead_statuses st ON st.slug = l.status LEFT JOIN services s ON s.id = l.service_id
         WHERE l.phone = ? AND l.deleted_at IS NULL AND st.is_closed = 0 AND l.id <> ? $vis ORDER BY l.id DESC",
        array_merge([$phone, $excludeId ?? 0], $params)
    );
}

/** Create a lead from the admin panel. $parentId links a re-enquiry to an earlier lead. */
function lead_admin_create(array $d, int $userId, ?int $parentId = null): array
{
    return db_transaction(function () use ($d, $userId, $parentId) {
        $customerId = db_value('SELECT id FROM customers WHERE phone = ? AND deleted_at IS NULL', [$d['phone']]);
        $hasHistory = $customerId || db_value('SELECT 1 FROM leads WHERE phone = ? LIMIT 1', [$d['phone']]);
        $number = next_number('LEAD');
        $status = setting('default_lead_status', 'new');
        $id = db_insert('leads', $d + [
            'lead_number' => $number, 'parent_lead_id' => $parentId, 'customer_id' => $customerId ?: null,
            'form_type' => 'admin', 'status' => $status, 'is_repeat_customer' => $hasHistory ? 1 : 0,
            'last_enquiry_at' => date('Y-m-d H:i:s'), 'created_by' => $userId,
            'assigned_to' => can('leads.assign') ? null : $userId,   // sales users own what they create
        ]);
        db_insert('lead_status_history', ['lead_id' => $id, 'old_status' => null, 'new_status' => $status, 'changed_by' => $userId,
            'note' => $parentId ? 'Re-enquiry created by staff' : 'Lead created by staff']);
        db_insert('lead_enquiries', ['lead_id' => $id, 'service_id' => $d['service_id'], 'form_type' => 'admin', 'source' => $d['source'],
            'problem_type' => $d['problem_type'], 'message' => $d['description'], 'preferred_date' => $d['preferred_date'],
            'preferred_time' => $d['preferred_time'], 'ip_address' => client_ip()]);
        if (!can('leads.assign')) {
            db_insert('lead_assignments', ['lead_id' => $id, 'assignment_type' => 'sales', 'assigned_to' => $userId,
                'assigned_by' => $userId, 'note' => 'Created by this user']);
        }
        if ($parentId) {
            db_query('UPDATE leads SET enquiry_count = enquiry_count + 1, last_enquiry_at = NOW() WHERE id = ?', [$parentId]);
        }
        audit_log($userId, 'created', 'leads', $id, "Created lead $number" . ($parentId ? ' (re-enquiry)' : ''));
        return ['id' => $id, 'lead_number' => $number];
    });
}

/** Add a staff-entered enquiry to an existing open lead instead of duplicating it. */
function lead_admin_merge(array $lead, array $d, int $userId): void
{
    db_transaction(function () use ($lead, $d, $userId) {
        db_query('UPDATE leads SET enquiry_count = enquiry_count + 1, last_enquiry_at = NOW() WHERE id = ?', [$lead['id']]);
        db_insert('lead_enquiries', ['lead_id' => $lead['id'], 'service_id' => $d['service_id'], 'form_type' => 'admin', 'source' => $d['source'],
            'problem_type' => $d['problem_type'], 'message' => $d['description'], 'preferred_date' => $d['preferred_date'],
            'preferred_time' => $d['preferred_time'], 'ip_address' => client_ip()]);
        db_insert('lead_notes', ['lead_id' => $lead['id'], 'user_id' => $userId,
            'note' => 'Repeat enquiry logged by staff' . ($d['description'] ? ': ' . $d['description'] : '.')]);
        audit_log($userId, 'merged', 'leads', (int) $lead['id'], "Repeat enquiry added to {$lead['lead_number']}");
    });
}

function lead_admin_update(array $lead, array $d, int $userId): void
{
    $old = array_intersect_key($lead, $d);
    $changed = array_filter($d, fn($v, $k) => (string) ($lead[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
    if (!$changed) {
        return;
    }
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($changed)));
    db_query("UPDATE leads SET $sets WHERE id = ?", array_merge(array_values($changed), [$lead['id']]));
    audit_log($userId, 'updated', 'leads', (int) $lead['id'], "Edited {$lead['lead_number']}: " . implode(', ', array_keys($changed)),
        array_intersect_key($old, $changed), $changed);
}

/** WhatsApp message for the customer: name, lead number, service and next booking only. */
function lead_whatsapp_text(array $lead, ?array $booking = null): string
{
    $name = $lead['customer_name'] ? strtok($lead['customer_name'], ' ') : 'there';
    $text = "Hi $name, this is " . setting('business_name') . " regarding your "
        . ($lead['service_name'] ? $lead['service_name'] . ' ' : '') . "request ({$lead['lead_number']}).";
    if ($booking) {
        $text .= " Your booking {$booking['booking_number']} is on " . date('D, d M', strtotime($booking['scheduled_date']))
            . ($booking['scheduled_time'] ? ", {$booking['scheduled_time']}" : '') . '.';
    }
    return $text;
}

/** Notify assignee (or admins) about follow-ups that just became overdue. Safe to call often. */
function followups_notify_overdue(): int
{
    $rows = db_all(
        "SELECT f.id, f.assigned_to, f.type, f.followup_date, l.lead_number, l.customer_name, l.phone
         FROM lead_followups f JOIN leads l ON l.id = f.lead_id
         WHERE f.status = 'pending' AND f.overdue_notified_at IS NULL AND l.deleted_at IS NULL
           AND (f.followup_date < CURDATE() OR (f.followup_date = CURDATE() AND f.followup_time IS NOT NULL AND f.followup_time < CURTIME()))
         LIMIT 200"
    );
    foreach ($rows as $f) {
        $title = "Follow-up overdue: {$f['lead_number']}";
        $msg = ($f['customer_name'] ?: $f['phone']) . ' · ' . (FOLLOWUP_TYPES[$f['type']] ?? $f['type']) . ' due ' . date('d M', strtotime($f['followup_date']));
        $link = 'admin/leads/view?number=' . $f['lead_number'];
        $f['assigned_to'] ? notify_user((int) $f['assigned_to'], 'followup.overdue', $title, $msg, $link)
                          : notify_roles(['super_admin', 'admin', 'manager'], 'followup.overdue', $title, $msg, $link);
        db_query('UPDATE lead_followups SET overdue_notified_at = NOW() WHERE id = ?', [$f['id']]);
    }
    return count($rows);
}
