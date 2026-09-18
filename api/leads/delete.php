<?php
/** POST number → soft-delete a lead (kept in DB, hidden everywhere, audit-logged). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin_post('leads.delete');
$lead = lead_or_404($_POST['number'] ?? '');

lead_soft_delete($lead, (int) $me['id']);
json_response(true, "Lead {$lead['lead_number']} deleted.");
