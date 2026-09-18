<?php
/** POST number, technician_id (empty = unassign). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('bookings.edit');
$b = booking_or_404($_POST['number'] ?? '');
if ((int) $b['is_closed']) {
    json_response(false, 'This booking is closed.', [], 409);
}
$techId = (int) ($_POST['technician_id'] ?? 0) ?: null;
try {
    booking_assign_technician($b, $techId, (int) $me['id']);
} catch (InvalidArgumentException $e) {
    json_response(false, $e->getMessage() . '.', [], 422);
}
json_response(true, $techId ? 'Technician assigned.' : 'Technician removed.');
