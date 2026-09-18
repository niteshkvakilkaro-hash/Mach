<?php
/** GET: latest notifications for the signed-in user + unread count. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_login();

$items = db_all(
    'SELECT id, type, title, message, link, read_at, created_at FROM notifications
     WHERE user_id = ? ORDER BY id DESC LIMIT 15',
    [$me['id']]
);
$unread = (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$me['id']]);

json_response(true, 'OK', [
    'unread' => $unread,
    'items'  => array_map(fn($n) => [
        'id'      => (int) $n['id'],
        'type'    => $n['type'],
        'title'   => $n['title'],
        'message' => $n['message'],
        'link'    => $n['link'] ? url($n['link']) : null,
        'read'    => $n['read_at'] !== null,
        'ago'     => time_ago($n['created_at']),
    ], $items),
]);
