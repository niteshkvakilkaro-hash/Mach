<?php
/** POST: mark one notification (id) or all (all=1) as read for the signed-in user. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}
$me = require_login();
if (!csrf_verify()) {
    json_response(false, 'Your session expired. Refresh the page and try again.', [], 419);
}

if (!empty($_POST['all'])) {
    db_query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$me['id']]);
} else {
    db_query('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [(int) ($_POST['id'] ?? 0), $me['id']]);
}
$unread = (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$me['id']]);
json_response(true, 'Updated', ['unread' => $unread]);
