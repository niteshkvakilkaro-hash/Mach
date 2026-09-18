<?php
/** POST-only logout (CSRF protected so a link on another site can't sign staff out). */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if ($id = auth_id()) {
        audit_log($id, 'logout', 'auth', $id, 'Signed out');
    }
    auth_logout();
    flash_set('login_notice', 'You have been signed out.');
}
redirect(url('admin/login'));
