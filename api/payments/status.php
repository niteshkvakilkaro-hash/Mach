<?php
/** POST number (payment), status (paid|failed|refunded), note. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('payments.edit');
$p = payment_find(clean_str($_POST['number'] ?? '', 20));
if (!$p) {
    json_response(false, 'Payment not found.', [], 404);
}
$to = (string) ($_POST['status'] ?? '');
if (!array_key_exists($to, PAYMENT_STATUSES)) {
    json_response(false, 'Invalid status.', [], 422);
}
try {
    payment_change_status($p, $to, clean_str($_POST['note'] ?? '', 255), (int) $me['id']);
} catch (InvalidArgumentException $e) {
    json_response(false, $e->getMessage() . '.', [], 422);
}
json_response(true, "{$p['payment_number']} marked " . strtolower(PAYMENT_STATUSES[$to]) . '.');
