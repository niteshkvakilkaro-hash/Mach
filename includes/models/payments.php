<?php
/**
 * Payments against bookings.
 * bookings.amount_paid and bookings.payment_status are caches recalculated from the payments table
 * after every change, so they can never drift.
 */

const PAYMENT_METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'online' => 'Online', 'other' => 'Other'];
const PAYMENT_STATUSES = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'];
const PAYMENT_STATUS_COLORS = ['pending' => 'warning', 'paid' => 'success', 'failed' => 'danger', 'refunded' => 'secondary'];

/** Amount the customer owes for a booking (final → quoted → estimated, minus discount), or null if not priced yet. */
function booking_amount_due(array $b): ?float
{
    $base = $b['final_amount'] ?? $b['quoted_amount'] ?? $b['estimated_amount'];
    return $base === null ? null : max(0, round((float) $base - (float) $b['discount_amount'], 2));
}

function booking_balance(array $b): ?float
{
    $due = booking_amount_due($b);
    return $due === null ? null : max(0, round($due - (float) $b['amount_paid'], 2));
}

/** Recalculate the cached paid amount and payment status of a booking. */
function booking_refresh_payment(int $bookingId): void
{
    $b = db_one('SELECT id, customer_id, final_amount, quoted_amount, estimated_amount, discount_amount FROM bookings WHERE id = ?', [$bookingId]);
    $sums = db_one(
        "SELECT COALESCE(SUM(IF(status = 'paid', amount, 0)), 0) AS paid, SUM(status = 'refunded') AS refunded FROM payments WHERE booking_id = ?",
        [$bookingId]
    );
    $paid = round((float) $sums['paid'], 2);
    $due = booking_amount_due($b);
    $status = match (true) {
        $paid > 0 && $due !== null && $paid + 0.009 >= $due => 'paid',
        $paid > 0                                            => 'partial',
        (int) $sums['refunded'] > 0                          => 'refunded',
        default                                              => 'unpaid',
    };
    db_query('UPDATE bookings SET amount_paid = ?, payment_status = ? WHERE id = ?', [$paid, $status, $bookingId]);
    customer_refresh_stats((int) $b['customer_id']);
}

/**
 * Validate a payment form. $balance null = booking not priced, any amount allowed.
 * @return array{0: array, 1: array<string,string>}
 */
function payment_validate(array $in, ?float $balance, bool $allowPending = true): array
{
    $errors = [];
    $amount = trim((string) ($in['amount'] ?? ''));
    if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 10000000) {
        $errors['amount'] = 'Enter the amount received.';
    } elseif ($balance !== null && (float) $amount > $balance + 0.009) {
        $errors['amount'] = 'Amount is more than the balance (' . format_inr($balance, true) . '). Update the final amount first if the bill changed.';
    }
    $method = array_key_exists($in['payment_method'] ?? '', PAYMENT_METHODS) ? $in['payment_method'] : '';
    if ($method === '') $errors['payment_method'] = 'Choose how the customer paid.';
    $txn = clean_str($in['transaction_id'] ?? '', 100);
    if (in_array($method, ['upi', 'card', 'bank_transfer', 'online'], true) && $txn === '' && !empty($in['require_txn'])) {
        $errors['transaction_id'] = 'Enter the UPI / transaction reference.';
    }
    $status = $allowPending && ($in['status'] ?? '') === 'pending' ? 'pending' : 'paid';
    $date = (string) ($in['payment_date'] ?? '');
    $dt = $date !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $date) : new DateTime();
    if (!$dt) {
        $errors['payment_date'] = 'Invalid date.';
    } elseif ($dt > new DateTime('+5 minutes')) {
        $errors['payment_date'] = 'Payment date cannot be in the future.';
    }
    return [[
        'amount' => round((float) $amount, 2), 'payment_method' => $method, 'transaction_id' => $txn ?: null,
        'status' => $status, 'payment_date' => $dt ? $dt->format('Y-m-d H:i:s') : null,
        'notes' => clean_str($in['notes'] ?? '', 500) ?: null,
    ], $errors];
}

/** Record a payment for a booking. $technicianId set when the technician collected it. */
function payment_record(array $b, array $d, int $userId, ?int $technicianId = null): array
{
    $result = db_transaction(function () use ($b, $d, $userId, $technicianId) {
        $number = next_number('PAY');
        $id = db_insert('payments', $d + [
            'payment_number' => $number, 'booking_id' => $b['id'], 'customer_id' => $b['customer_id'],
            'collected_by_user' => $technicianId ? null : $userId, 'collected_by_technician' => $technicianId, 'created_by' => $userId,
        ]);
        booking_refresh_payment((int) $b['id']);
        db_insert('booking_status_history', ['booking_id' => $b['id'], 'old_status' => $b['status'], 'new_status' => $b['status'], 'changed_by' => $userId,
            'note' => ($d['status'] === 'paid' ? 'Payment received: ' : 'Payment pending: ') . format_inr($d['amount']) . ' by ' . PAYMENT_METHODS[$d['payment_method']] . " ($number)"]);
        audit_log($userId, 'created', 'payments', $id, "Recorded $number: " . format_inr($d['amount']) . " for {$b['booking_number']}", null, $d);
        return ['id' => $id, 'payment_number' => $number];
    });
    if ($d['status'] === 'paid') {
        try {
            notify_roles(['super_admin', 'admin', 'accountant'], 'payment.received', 'Payment received: ' . format_inr($d['amount']),
                "{$b['booking_number']} · {$b['customer_name']} · " . PAYMENT_METHODS[$d['payment_method']], 'admin/payments/receipt?number=' . $result['payment_number']);
        } catch (Throwable $e) {
            app_log('warning', 'Payment notification failed: ' . $e->getMessage());
        }
        mail_event('mail_event_payment_receipt', $result['payment_number']);
    }
    return $result;
}

function payment_find(string $number): ?array
{
    return db_one(
        "SELECT p.*, b.booking_number, b.scheduled_date, b.final_amount, b.quoted_amount, b.estimated_amount, b.discount_amount,
                b.amount_paid, b.payment_status, b.address, b.warranty_until, s.name AS service_name,
                c.name AS customer_name, c.phone, c.customer_number, u.name AS collected_by_user_name, t.name AS collected_by_technician_name
         FROM payments p
         JOIN bookings b ON b.id = p.booking_id
         JOIN customers c ON c.id = p.customer_id
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN users u ON u.id = p.collected_by_user
         LEFT JOIN technicians t ON t.id = p.collected_by_technician
         WHERE p.payment_number = ?",
        [$number]
    );
}

/** Allowed status changes: pending → paid/failed, paid → refunded. */
function payment_change_status(array $p, string $to, string $note, int $userId): void
{
    $allowed = ['pending' => ['paid', 'failed'], 'paid' => ['refunded'], 'failed' => [], 'refunded' => []];
    if (!in_array($to, $allowed[$p['status']] ?? [], true)) {
        throw new InvalidArgumentException('A ' . strtolower(PAYMENT_STATUSES[$p['status']]) . ' payment cannot be changed to ' . strtolower(PAYMENT_STATUSES[$to] ?? $to));
    }
    if ($to === 'refunded' && mb_strlen($note) < 3) {
        throw new InvalidArgumentException('Please give a reason for the refund');
    }
    db_transaction(function () use ($p, $to, $note, $userId) {
        $notes = trim(($p['notes'] ? $p['notes'] . "\n" : '') . PAYMENT_STATUSES[$to] . ($note !== '' ? ": $note" : ''));
        db_query('UPDATE payments SET status = ?, notes = ?, payment_date = IF(? = \'paid\' AND payment_date IS NULL, NOW(), payment_date) WHERE id = ?',
            [$to, mb_substr($notes, 0, 500), $to, $p['id']]);
        booking_refresh_payment((int) $p['booking_id']);
        audit_log($userId, 'status_changed', 'payments', (int) $p['id'], "{$p['payment_number']}: {$p['status']} → $to" . ($note !== '' ? " ($note)" : ''),
            ['status' => $p['status']], ['status' => $to]);
    });
    if ($to === 'paid') {
        mail_event('mail_event_payment_receipt', $p['payment_number']);
    }
}

// ---------------------------------------------------------------- list & totals

function payment_filters(array $q): array
{
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : '';
    $from = $date($q['from'] ?? '');
    $to = $date($q['to'] ?? '');
    if (!isset($q['from']) && !isset($q['to'])) {
        $from = date('Y-m-01');              // default: this month
        $to = date('Y-m-d');
    }
    return [
        'q'      => clean_str($q['q'] ?? '', 100),
        'status' => array_key_exists($q['status'] ?? '', PAYMENT_STATUSES) ? $q['status'] : '',
        'method' => array_key_exists($q['method'] ?? '', PAYMENT_METHODS) ? $q['method'] : '',
        'from'   => $from,
        'to'     => $to,
    ];
}

function payment_where(array $f, bool $skipStatus = false): array
{
    $sql = '1=1';
    $params = [];
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $digits = preg_replace('/\D+/', '', $f['q']);
        $sql .= ' AND (p.payment_number LIKE ? OR b.booking_number LIKE ? OR c.name LIKE ? OR p.transaction_id LIKE ?' . (strlen($digits) >= 4 ? ' OR c.phone LIKE ?' : '') . ')';
        array_push($params, $like, $like, $like, $like);
        if (strlen($digits) >= 4) $params[] = '%' . substr($digits, -10) . '%';
    }
    if (!$skipStatus && $f['status'] !== '') { $sql .= ' AND p.status = ?'; $params[] = $f['status']; }
    if ($f['method'] !== '') { $sql .= ' AND p.payment_method = ?'; $params[] = $f['method']; }
    if ($f['from'] !== '') { $sql .= ' AND COALESCE(p.payment_date, p.created_at) >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $sql .= ' AND COALESCE(p.payment_date, p.created_at) <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    return [$sql, $params];
}

const PAYMENT_JOINS = 'FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN customers c ON c.id = p.customer_id';

function payment_search(array $f, int $page, int $perPage = 25): array
{
    [$where, $params] = payment_where($f);
    $total = (int) db_value('SELECT COUNT(*) ' . PAYMENT_JOINS . " WHERE $where", $params);
    $offset = ($page - 1) * $perPage;
    $rows = db_all(
        'SELECT p.*, b.booking_number, c.name AS customer_name, c.phone, u.name AS user_name, t.name AS technician_name ' . PAYMENT_JOINS . "
         LEFT JOIN users u ON u.id = p.collected_by_user LEFT JOIN technicians t ON t.id = p.collected_by_technician
         WHERE $where ORDER BY COALESCE(p.payment_date, p.created_at) DESC, p.id DESC LIMIT $perPage OFFSET $offset",
        $params
    );
    return ['total' => $total, 'rows' => $rows];
}

/** Totals for the summary cards (ignores the status filter so all buckets show). */
function payment_totals(array $f): array
{
    [$where, $params] = payment_where($f, true);
    $row = db_one(
        "SELECT COALESCE(SUM(IF(p.status = 'paid', p.amount, 0)), 0) AS collected, SUM(p.status = 'paid') AS paid_count,
                COALESCE(SUM(IF(p.status = 'pending', p.amount, 0)), 0) AS pending,
                COALESCE(SUM(IF(p.status = 'refunded', p.amount, 0)), 0) AS refunded " . PAYMENT_JOINS . " WHERE $where",
        $params
    );
    $byMethod = db_all(
        "SELECT p.payment_method, SUM(p.amount) AS total, COUNT(*) AS n " . PAYMENT_JOINS . " WHERE $where AND p.status = 'paid' GROUP BY p.payment_method ORDER BY total DESC",
        $params
    );
    return $row + ['by_method' => $byMethod];
}

/** Money still to be collected on completed / payment-pending jobs (all time). */
function payments_outstanding(): array
{
    return db_one(
        "SELECT COUNT(*) AS jobs,
                COALESCE(SUM(GREATEST(COALESCE(b.final_amount, b.quoted_amount, b.estimated_amount, 0) - b.discount_amount - b.amount_paid, 0)), 0) AS amount
         FROM bookings b WHERE b.deleted_at IS NULL AND b.status IN ('completed', 'payment_pending') AND b.payment_status IN ('unpaid', 'partial')"
    );
}

/** Plain-text receipt for WhatsApp. */
function payment_receipt_text(array $p): string
{
    return setting('business_name') . " — Payment receipt\n"
        . "Receipt: {$p['payment_number']}\nBooking: {$p['booking_number']}" . ($p['service_name'] ? " ({$p['service_name']})" : '') . "\n"
        . 'Amount: ' . format_inr($p['amount'], true) . ' by ' . PAYMENT_METHODS[$p['payment_method']] . "\n"
        . 'Date: ' . date('d M Y, h:i A', strtotime($p['payment_date'] ?? $p['created_at'])) . "\nThank you!";
}
