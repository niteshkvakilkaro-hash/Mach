<?php
/** POST number (booking), amount, payment_method, transaction_id, payment_date, status, collected_by (office|technician), notes. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('payments.create');
$b = booking_or_404($_POST['number'] ?? '');
if ($b['status'] === 'cancelled') {
    json_response(false, 'This booking is cancelled.', [], 409);
}
[$data, $errors] = payment_validate($_POST, booking_balance($b));
if ($errors) {
    json_response(false, reset($errors), [], 422, $errors);
}
$techId = ($_POST['collected_by'] ?? '') === 'technician' && $b['technician_id'] ? (int) $b['technician_id'] : null;
$p = payment_record($b, $data, (int) $me['id'], $techId);
json_response(true, ($data['status'] === 'paid' ? 'Payment recorded: ' : 'Pending payment added: ') . format_inr($data['amount']) . " ({$p['payment_number']}).",
    ['payment_number' => $p['payment_number'], 'receipt' => url('admin/payments/receipt?number=' . $p['payment_number'])]);
