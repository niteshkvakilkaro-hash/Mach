<?php
/** POST number, date, time, reason → move the visit. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('bookings.edit');
$b = booking_or_404($_POST['number'] ?? '');
if ((int) $b['is_closed']) {
    json_response(false, 'Completed or cancelled bookings cannot be rescheduled.', [], 409);
}
$date = (string) ($_POST['date'] ?? '');
$d = DateTime::createFromFormat('!Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    json_response(false, 'Pick today or a future date.', [], 422, ['date' => 'Invalid date']);
}
$time = clean_str($_POST['time'] ?? '', 40);
if ($time === '') {
    json_response(false, 'Choose a time slot.', [], 422, ['time' => 'Required']);
}
if ($date === $b['scheduled_date'] && $time === $b['scheduled_time']) {
    json_response(false, 'That is the current schedule.', [], 422);
}
booking_reschedule($b, $date, $time, clean_str($_POST['reason'] ?? '', 255), (int) $me['id']);
json_response(true, 'Booking moved to ' . date('D, d M', strtotime($date)) . ", $time.");
