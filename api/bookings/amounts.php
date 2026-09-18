<?php
/** POST number, estimated_amount, quoted_amount, discount_amount, final_amount, notes. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('bookings.edit');
$b = booking_or_404($_POST['number'] ?? '');

$amounts = [];
$errors = [];
foreach (['estimated_amount', 'quoted_amount', 'discount_amount', 'final_amount'] as $k) {
    $v = trim((string) ($_POST[$k] ?? ''));
    if ($v === '') {
        $amounts[$k] = $k === 'discount_amount' ? 0 : null;
    } elseif (!is_numeric($v) || (float) $v < 0 || (float) $v > 10000000) {
        $errors[$k] = 'Enter a valid amount.';
    } else {
        $amounts[$k] = round((float) $v, 2);
    }
}
$base = $amounts['final_amount'] ?? $amounts['quoted_amount'] ?? $amounts['estimated_amount'];
if (!$errors && $base !== null && $amounts['discount_amount'] > $base) {
    $errors['discount_amount'] = 'Discount cannot be more than the amount.';
}
if ($errors) {
    json_response(false, 'Please check the amounts.', [], 422, $errors);
}
booking_update_amounts($b, $amounts, clean_str($_POST['notes'] ?? '', 2000) ?: null, (int) $me['id']);
json_response(true, 'Amounts saved.');
