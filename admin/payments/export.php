<?php
/** CSV export of payments for the current filters. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('payments.view');

$f = payment_filters($_GET);
[$where, $params] = payment_where($f);
audit_log((int) $me['id'], 'exported', 'payments', null, 'Exported payments CSV', null, $f);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payments-' . date('Y-m-d-His') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Payment number', 'Date', 'Customer', 'Phone', 'Booking', 'Amount', 'Method', 'Reference', 'Status', 'Collected by', 'Notes']);
$safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
$stmt = db_query(
    'SELECT p.payment_number, COALESCE(p.payment_date, p.created_at) AS dt, c.name, c.phone, b.booking_number, p.amount, p.payment_method,
            p.transaction_id, p.status, COALESCE(t.name, u.name) AS collector, p.notes ' . PAYMENT_JOINS . '
     LEFT JOIN users u ON u.id = p.collected_by_user LEFT JOIN technicians t ON t.id = p.collected_by_technician
     WHERE ' . $where . ' ORDER BY dt DESC',
    $params
);
while ($r = $stmt->fetch()) {
    $r['payment_method'] = PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'];
    $r['status'] = PAYMENT_STATUSES[$r['status']] ?? $r['status'];
    fputcsv($out, array_map($safe, array_values($r)));
}
fclose($out);
