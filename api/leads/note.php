<?php
/** POST number, note → add an internal note. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('leads.edit');
$lead = lead_or_404($_POST['number'] ?? '');

$note = clean_str($_POST['note'] ?? '', 2000);
if (mb_strlen($note) < 2) {
    json_response(false, 'Write a note first.', [], 422, ['note' => 'Write a note first.']);
}
lead_add_note($lead, $note, (int) $me['id']);
json_response(true, 'Note added.');
