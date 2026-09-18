<?php
/**
 * POST number + action:
 *   create:  date, time, type, assigned_to, note
 *   close:   id, status (completed|cancelled), outcome
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('leads.edit');
$lead = lead_or_404($_POST['number'] ?? '');

if (($_POST['action'] ?? '') === 'close') {
    $status = ($_POST['status'] ?? '') === 'cancelled' ? 'cancelled' : 'completed';
    if (!followup_close($lead, (int) ($_POST['id'] ?? 0), $status, clean_str($_POST['outcome'] ?? '', 500), (int) $me['id'])) {
        json_response(false, 'This follow-up is no longer pending.', [], 409);
    }
    json_response(true, 'Follow-up marked ' . $status . '.');
}

$errors = [];
$date = (string) ($_POST['date'] ?? '');
$d = DateTime::createFromFormat('!Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    $errors['date'] = 'Pick today or a future date.';
}
$time = (string) ($_POST['time'] ?? '');
if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
    $errors['time'] = 'Invalid time.';
}
$type = array_key_exists($_POST['type'] ?? '', FOLLOWUP_TYPES) ? $_POST['type'] : 'call';
$assignee = (int) ($_POST['assigned_to'] ?? 0) ?: null;
if ($assignee && !in_array($assignee, array_map('intval', array_column(lead_staff_options(), 'id')), true)) {
    $errors['assigned_to'] = 'Choose an active staff member.';
}
if ($errors) {
    json_response(false, 'Please check the follow-up details.', [], 422, $errors);
}

followup_create($lead, [
    'date' => $date, 'time' => $time !== '' ? $time . ':00' : null, 'type' => $type,
    'assigned_to' => $assignee, 'note' => clean_str($_POST['note'] ?? '', 500) ?: null,
], (int) $me['id']);
if (in_array($lead['status'], ['new', 'contacted'], true)) {
    lead_change_status($lead, 'follow_up', 'Follow-up scheduled', (int) $me['id']);
}
json_response(true, 'Follow-up scheduled.');
