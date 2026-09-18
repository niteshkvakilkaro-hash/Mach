<?php
/** POST number, amount, payment_method, transaction_id → technician records money collected on their own job. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}
require_permission('jobs.update_own');
if (!csrf_verify()) {
    json_response(false, 'Your session expired. Refresh the page and try again.', [], 419);
}
[$me, $tech] = tech_require();
$b = tech_job(clean_str($_POST['number'] ?? '', 20), (int) $tech['id']);
if (!$b) {
    json_response(false, 'Job not found or no longer assigned to you.', [], 404);
}
if ($b['status'] === 'cancelled') {
    json_response(false, 'This job is cancelled.', [], 409);
}
$in = $_POST;
unset($in['payment_date'], $in['status']);         // technicians record money received now, always "paid"
$in['require_txn'] = 1;                            // UPI / card need a reference
[$data, $errors] = payment_validate($in, booking_balance($b), false);
if ($errors) {
    json_response(false, reset($errors), [], 422, $errors);
}
$p = payment_record($b, $data, (int) $me['id'], (int) $tech['id']);
json_response(true, 'Payment saved: ' . format_inr($data['amount']) . " ({$p['payment_number']}).");
