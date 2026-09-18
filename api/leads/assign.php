<?php
/** POST number, type (sales|support|technician), user_id | technician_id → assign or unassign. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('leads.assign');
$lead = lead_or_404($_POST['number'] ?? '');

$type = in_array($_POST['type'] ?? '', ['sales', 'support', 'technician'], true) ? $_POST['type'] : 'sales';
$target = (int) ($type === 'technician' ? ($_POST['technician_id'] ?? 0) : ($_POST['user_id'] ?? 0)) ?: null;

try {
    lead_assign($lead, $type, $target, clean_str($_POST['note'] ?? '', 255), (int) $me['id']);
} catch (InvalidArgumentException $e) {
    json_response(false, $e->getMessage() . '.', [], 422);
}
json_response(true, $target ? 'Lead assigned.' : 'Assignment removed.');
