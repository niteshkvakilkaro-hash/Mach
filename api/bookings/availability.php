<?php
/** GET date → each technician's open jobs that day (for the booking form). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('bookings.view');
$date = (string) ($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response(false, 'Invalid date.', [], 422);
}
json_response(true, 'OK', ['date' => $date, 'technicians' => technician_day_load($date)]);
