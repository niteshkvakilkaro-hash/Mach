<?php
/** POST number → soft-delete a customer. Blocked while the customer has open bookings. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('customers.delete');
$c = customer_find(clean_str($_POST['number'] ?? '', 20));
if (!$c) {
    json_response(false, 'Customer not found.', [], 404);
}
$open = (int) db_value(
    "SELECT COUNT(*) FROM bookings b JOIN booking_statuses s ON s.slug = b.status WHERE b.customer_id = ? AND b.deleted_at IS NULL AND s.is_closed = 0",
    [$c['id']]
);
if ($open) {
    json_response(false, "This customer has $open open booking(s). Complete or cancel them first.", [], 409);
}
db_query('UPDATE customers SET deleted_at = NOW() WHERE id = ?', [$c['id']]);
audit_log((int) $me['id'], 'deleted', 'customers', (int) $c['id'], "Deleted customer {$c['customer_number']}",
    ['name' => $c['name'], 'phone' => $c['phone'], 'total_spent' => $c['total_spent']]);
json_response(true, "Customer {$c['customer_number']} deleted.");
