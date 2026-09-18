<?php
/** POST number, status, note → change a lead's status (history + audit). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('leads.edit');
$lead = lead_or_404($_POST['number'] ?? '');

$status = (string) ($_POST['status'] ?? '');
$note = clean_str($_POST['note'] ?? '', 500);
if (!isset(lead_statuses()[$status])) {
    json_response(false, 'Choose a valid status.', [], 422, ['status' => 'Choose a valid status.']);
}
if ($status === $lead['status']) {
    json_response(false, 'The lead already has this status.', [], 422);
}
if (in_array($status, LEAD_LOST_STATUSES, true) && mb_strlen($note) < 3) {
    json_response(false, 'Please add a reason when closing a lead.', [], 422, ['note' => 'Reason is required for this status.']);
}

lead_change_status($lead, $status, $note, (int) $me['id']);
json_response(true, 'Status updated to ' . lead_statuses()[$status]['label'] . '.', ['status' => $status]);
