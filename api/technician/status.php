<?php
/**
 * POST number, status, note, [quoted_amount | final_amount]
 * Technician updates the stage of their OWN job. Only statuses flagged technician_can_set are allowed;
 * cancelling and rescheduling stay with the office.
 */
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
if ((int) $b['is_closed']) {
    json_response(false, 'This job is already closed.', [], 409);
}
$status = (string) ($_POST['status'] ?? '');
if (!isset(tech_allowed_statuses()[$status])) {
    json_response(false, 'You cannot set this stage. Please call the office.', [], 403);
}
if ($status === $b['status']) {
    json_response(false, 'The job is already at this stage.', [], 422);
}

// Amounts the technician enters at quotation and payment stages
$amounts = [];
foreach (['quoted_amount', 'final_amount'] as $k) {
    $v = trim((string) ($_POST[$k] ?? ''));
    if ($v === '') continue;
    if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 1000000) {
        json_response(false, 'Enter a valid amount.', [], 422, [$k => 'Invalid amount']);
    }
    $amounts[$k] = round((float) $v, 2);
}
if ($status === 'quotation_sent' && !isset($amounts['quoted_amount']) && $b['quoted_amount'] === null) {
    json_response(false, 'Enter the quotation amount.', [], 422, ['quoted_amount' => 'Required']);
}
if (in_array($status, ['payment_pending', 'completed'], true) && !isset($amounts['final_amount']) && $b['final_amount'] === null && $b['quoted_amount'] === null && $b['estimated_amount'] === null) {
    json_response(false, 'Enter the final amount.', [], 422, ['final_amount' => 'Required']);
}

$note = clean_str($_POST['note'] ?? '', 500);
if ($amounts) {
    $label = isset($amounts['quoted_amount']) ? 'Quotation ' . format_inr($amounts['quoted_amount']) : 'Final amount ' . format_inr($amounts['final_amount']);
    $note = trim($label . ($note !== '' ? ' — ' . $note : ''));
    booking_update_amounts($b, $amounts, $b['notes'], (int) $me['id']);
    $b = booking_find($b['booking_number']);
}
booking_change_status($b, $status, $note, (int) $me['id']);

if ($status === 'quotation_sent') {
    notify_roles(['super_admin', 'admin', 'manager'], 'booking.quotation', "Quotation sent: {$b['booking_number']}",
        "{$tech['name']} · " . format_inr($amounts['quoted_amount'] ?? $b['quoted_amount']), 'admin/bookings/view?number=' . $b['booking_number']);
}
json_response(true, 'Updated: ' . booking_statuses()[$status]['label'] . '.');
