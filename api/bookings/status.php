<?php
/** POST number, status, note → change booking status (with side effects). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('bookings.edit');
$b = booking_or_404($_POST['number'] ?? '');

$status = (string) ($_POST['status'] ?? '');
if (!isset(booking_statuses()[$status])) {
    json_response(false, 'Choose a valid status.', [], 422);
}
if ($status === $b['status']) {
    json_response(false, 'The booking already has this status.', [], 422);
}
try {
    booking_change_status($b, $status, clean_str($_POST['note'] ?? '', 500), (int) $me['id']);
} catch (InvalidArgumentException $e) {
    json_response(false, $e->getMessage() . '.', [], 422, ['note' => $e->getMessage()]);
}
json_response(true, 'Status updated to ' . booking_statuses()[$status]['label'] . '.');
