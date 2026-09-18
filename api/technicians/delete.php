<?php
/** POST number (= technician id) → soft-delete a technician and disable their login. Blocked while they have open jobs. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('technicians.delete');
$t = technician_find((int) ($_POST['number'] ?? 0));
if (!$t) {
    json_response(false, 'Technician not found.', [], 404);
}
$open = (int) db_value(
    "SELECT COUNT(*) FROM bookings b JOIN booking_statuses s ON s.slug = b.status WHERE b.technician_id = ? AND b.deleted_at IS NULL AND s.is_closed = 0",
    [$t['id']]
);
if ($open) {
    json_response(false, "{$t['name']} has $open open job(s). Reassign them first.", [], 409);
}
db_transaction(function () use ($t, $me) {
    db_query("UPDATE technicians SET deleted_at = NOW(), status = 'inactive' WHERE id = ?", [$t['id']]);
    if ($t['user_id']) {
        db_query("UPDATE users SET status = 'inactive' WHERE id = ?", [$t['user_id']]);
    }
    audit_log((int) $me['id'], 'deleted', 'technicians', (int) $t['id'], "Removed technician {$t['name']}");
});
json_response(true, "{$t['name']} removed.");
