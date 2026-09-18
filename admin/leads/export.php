<?php
/** CSV export of the current lead list filters (streams, so it works for large lists). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('leads.export');

$filters = lead_filters($_GET);
[$where, $params] = lead_where($filters);
audit_log((int) $me['id'], 'exported', 'leads', null, 'Exported leads CSV', null, $filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d-His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and Hindi names correctly
fputcsv($out, ['Lead number', 'Created', 'Customer', 'Phone', 'Email', 'Service', 'Problem', 'Area', 'City', 'Pincode',
    'Preferred date', 'Preferred time', 'Source', 'Form', 'UTM source', 'UTM medium', 'UTM campaign', 'Status', 'Priority',
    'Assigned to', 'Technician', 'Enquiries', 'Repeat customer']);

/** Neutralise spreadsheet formulas in user-supplied text (CSV injection). */
$safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;

$stmt = db_query(
    "SELECT l.lead_number, l.created_at, l.customer_name, l.phone, l.email, s.name AS service_name, l.problem_type,
            loc.name AS area_name, l.city, l.pincode, l.preferred_date, l.preferred_time, l.source, l.form_type,
            l.utm_source, l.utm_medium, l.utm_campaign, st.label AS status_label, l.priority,
            u.name AS assigned_name, t.name AS technician_name, l.enquiry_count, l.is_repeat_customer
     FROM leads l
     JOIN lead_statuses st ON st.slug = l.status
     LEFT JOIN services s ON s.id = l.service_id
     LEFT JOIN locations loc ON loc.id = l.location_id
     LEFT JOIN users u ON u.id = l.assigned_to
     LEFT JOIN technicians t ON t.id = l.technician_id
     WHERE $where ORDER BY l.id DESC",
    $params
);
while ($r = $stmt->fetch()) {
    $r['source'] = LEAD_SOURCES[$r['source']] ?? $r['source'];
    $r['is_repeat_customer'] = $r['is_repeat_customer'] ? 'Yes' : 'No';
    fputcsv($out, array_map($safe, array_values($r)));
}
fclose($out);
