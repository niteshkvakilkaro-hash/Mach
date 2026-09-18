<?php
/**
 * Business reports for a date range. Every report returns plain rows so the same data feeds
 * the on-screen table, the chart and the CSV export. Leads respect row-level visibility.
 * "Converted" = lead whose current status is a won status (Scheduled onwards, incl. Completed).
 */

function report_range(array $q): array
{
    $valid = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime($v) ? $v : null;
    $from = $valid($q['from'] ?? '') ?? date('Y-m-01');
    $to = $valid($q['to'] ?? '') ?? date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    return ['from' => $from, 'to' => $to, 'days' => $days, 'start' => "$from 00:00:00", 'end' => "$to 23:59:59"];
}

function report_overview(array $r): array
{
    [$vis, $vp] = lead_visibility('l');
    $leads = db_one(
        "SELECT COUNT(*) AS leads, SUM(s.is_won = 1) AS converted, SUM(l.status IN ('cancelled','not_interested','invalid')) AS lost,
                SUM(l.form_type = 'callback') AS callbacks, SUM(l.is_repeat_customer = 1) AS repeat_leads
         FROM leads l JOIN lead_statuses s ON s.slug = l.status
         WHERE l.deleted_at IS NULL AND l.created_at BETWEEN ? AND ? $vis",
        array_merge([$r['start'], $r['end']], $vp)
    );
    $jobs = db_one(
        "SELECT COUNT(*) AS bookings, SUM(status = 'completed') AS completed, SUM(status = 'cancelled') AS cancelled,
                COALESCE(AVG(IF(status = 'completed', final_amount - discount_amount, NULL)), 0) AS avg_job
         FROM bookings WHERE deleted_at IS NULL AND scheduled_date BETWEEN ? AND ?",
        [$r['from'], $r['to']]
    );
    $money = db_one(
        "SELECT COALESCE(SUM(IF(status = 'paid', amount, 0)), 0) AS collected, COALESCE(SUM(IF(status = 'refunded', amount, 0)), 0) AS refunded
         FROM payments WHERE COALESCE(payment_date, created_at) BETWEEN ? AND ?",
        [$r['start'], $r['end']]
    );
    $newCustomers = (int) db_value('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?', [$r['start'], $r['end']]);
    $out = array_map(fn($v) => (float) $v, $leads + $jobs + $money);
    $out['new_customers'] = $newCustomers;
    $out['conversion'] = pct($out['converted'], $out['leads']);
    $out['completion'] = pct($out['completed'], $out['bookings']);
    return $out;
}

/** Leads (and converted) per day, or per month for ranges over ~2 months. */
function report_leads_timeline(array $r): array
{
    [$vis, $vp] = lead_visibility('l');
    $monthly = $r['days'] > 62;
    $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';
    $rows = db_all(
        "SELECT DATE_FORMAT(l.created_at, '$fmt') AS k, COUNT(*) AS leads, SUM(s.is_won = 1) AS converted
         FROM leads l JOIN lead_statuses s ON s.slug = l.status
         WHERE l.deleted_at IS NULL AND l.created_at BETWEEN ? AND ? $vis GROUP BY k",
        array_merge([$r['start'], $r['end']], $vp)
    );
    $map = array_column($rows, null, 'k');
    $out = [];
    $cursor = strtotime($monthly ? date('Y-m-01', strtotime($r['from'])) : $r['from']);
    $end = strtotime($r['to']);
    while ($cursor <= $end) {
        $k = date($monthly ? 'Y-m' : 'Y-m-d', $cursor);
        $out[] = ['label' => date($monthly ? 'M Y' : 'd M', $cursor), 'leads' => (int) ($map[$k]['leads'] ?? 0), 'converted' => (int) ($map[$k]['converted'] ?? 0)];
        $cursor = strtotime($monthly ? '+1 month' : '+1 day', $cursor);
    }
    return $out;
}

/** Leads grouped by a dimension: service | source | staff | area | form. */
function report_leads_by(string $dim, array $r): array
{
    [$vis, $vp] = lead_visibility('l');
    [$label, $join] = match ($dim) {
        'service' => ["COALESCE(sv.name, 'Not specified')", 'LEFT JOIN services sv ON sv.id = l.service_id'],
        'staff'   => ["COALESCE(u.name, 'Unassigned')", 'LEFT JOIN users u ON u.id = l.assigned_to'],
        'area'    => ["COALESCE(loc.name, NULLIF(l.city, ''), 'Unknown')", 'LEFT JOIN locations loc ON loc.id = l.location_id'],
        'form'    => ['l.form_type', ''],
        default   => ['l.source', ''],
    };
    $rows = db_all(
        "SELECT $label AS label, COUNT(*) AS leads, SUM(s.is_won = 1) AS converted,
                SUM(l.status = 'completed') AS completed, SUM(l.status IN ('cancelled','not_interested','invalid')) AS lost,
                SUM(s.is_closed = 0 AND s.is_won = 0) AS open_leads
         FROM leads l JOIN lead_statuses s ON s.slug = l.status $join
         WHERE l.deleted_at IS NULL AND l.created_at BETWEEN ? AND ? $vis
         GROUP BY 1 ORDER BY leads DESC",
        array_merge([$r['start'], $r['end']], $vp)
    );
    foreach ($rows as &$row) {
        if ($dim === 'source') $row['label'] = LEAD_SOURCES[$row['label']] ?? ucfirst((string) $row['label']);
        if ($dim === 'form') $row['label'] = ucfirst((string) $row['label']);
        foreach (['leads', 'converted', 'completed', 'lost', 'open_leads'] as $k) $row[$k] = (int) $row[$k];
        $row['conversion'] = pct($row['converted'], $row['leads']);
    }
    return $rows;
}

/** Technician-wise jobs in the range (by scheduled date). */
function report_technicians(array $r): array
{
    $rows = db_all(
        "SELECT COALESCE(t.name, 'Not assigned') AS label, COUNT(*) AS jobs, SUM(b.status = 'completed') AS completed,
                SUM(b.status = 'cancelled') AS cancelled, SUM(bs.is_closed = 0) AS open_jobs, SUM(b.reschedule_count > 0) AS rescheduled,
                COALESCE(SUM(IF(b.status = 'completed', b.final_amount - b.discount_amount, 0)), 0) AS job_value,
                (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.collected_by_technician = t.id AND p.status = 'paid'
                   AND COALESCE(p.payment_date, p.created_at) BETWEEN ? AND ?) AS collected
         FROM bookings b JOIN booking_statuses bs ON bs.slug = b.status LEFT JOIN technicians t ON t.id = b.technician_id
         WHERE b.deleted_at IS NULL AND b.scheduled_date BETWEEN ? AND ?
         GROUP BY t.id ORDER BY completed DESC, jobs DESC",
        [$r['start'], $r['end'], $r['from'], $r['to']]
    );
    foreach ($rows as &$row) {
        foreach (['jobs', 'completed', 'cancelled', 'open_jobs', 'rescheduled'] as $k) $row[$k] = (int) $row[$k];
        $row['completion'] = pct($row['completed'], $row['jobs']);
    }
    return $rows;
}

/** Completed / cancelled jobs by service, plus top cancellation reasons. */
function report_jobs_by_service(array $r): array
{
    $rows = db_all(
        "SELECT COALESCE(s.name, 'Not specified') AS label, COUNT(*) AS jobs, SUM(b.status = 'completed') AS completed,
                SUM(b.status = 'cancelled') AS cancelled, COALESCE(SUM(IF(b.status = 'completed', b.final_amount - b.discount_amount, 0)), 0) AS job_value
         FROM bookings b LEFT JOIN services s ON s.id = b.service_id
         WHERE b.deleted_at IS NULL AND b.scheduled_date BETWEEN ? AND ? GROUP BY 1 ORDER BY jobs DESC",
        [$r['from'], $r['to']]
    );
    foreach ($rows as &$row) {
        foreach (['jobs', 'completed', 'cancelled'] as $k) $row[$k] = (int) $row[$k];
        $row['avg_value'] = $row['completed'] ? round($row['job_value'] / $row['completed']) : 0;
    }
    return $rows;
}

function report_cancel_reasons(array $r): array
{
    return db_all(
        "SELECT COALESCE(NULLIF(cancel_reason, ''), 'No reason given') AS label, COUNT(*) AS jobs
         FROM bookings WHERE deleted_at IS NULL AND status = 'cancelled' AND scheduled_date BETWEEN ? AND ?
         GROUP BY 1 ORDER BY jobs DESC LIMIT 10",
        [$r['from'], $r['to']]
    );
}

/** Collections per day (or month for long ranges). */
function report_revenue_timeline(array $r): array
{
    $monthly = $r['days'] > 62;
    $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';
    $map = array_column(db_all(
        "SELECT DATE_FORMAT(COALESCE(payment_date, created_at), '$fmt') AS k, SUM(amount) AS total, COUNT(*) AS n
         FROM payments WHERE status = 'paid' AND COALESCE(payment_date, created_at) BETWEEN ? AND ? GROUP BY k",
        [$r['start'], $r['end']]
    ), null, 'k');
    $out = [];
    $cursor = strtotime($monthly ? date('Y-m-01', strtotime($r['from'])) : $r['from']);
    while ($cursor <= strtotime($r['to'])) {
        $k = date($monthly ? 'Y-m' : 'Y-m-d', $cursor);
        $out[] = ['label' => date($monthly ? 'M Y' : 'd M', $cursor), 'collected' => round((float) ($map[$k]['total'] ?? 0)), 'payments' => (int) ($map[$k]['n'] ?? 0)];
        $cursor = strtotime($monthly ? '+1 month' : '+1 day', $cursor);
    }
    return $out;
}

function report_revenue_by(string $dim, array $r): array
{
    [$label, $join] = match ($dim) {
        'service' => ["COALESCE(s.name, 'Not specified')", 'JOIN bookings b ON b.id = p.booking_id LEFT JOIN services s ON s.id = b.service_id'],
        'collector' => ["COALESCE(t.name, u.name, 'Office')", 'LEFT JOIN technicians t ON t.id = p.collected_by_technician LEFT JOIN users u ON u.id = p.collected_by_user'],
        default   => ['p.payment_method', ''],
    };
    $rows = db_all(
        "SELECT $label AS label, COUNT(*) AS payments, SUM(p.amount) AS collected
         FROM payments p $join WHERE p.status = 'paid' AND COALESCE(p.payment_date, p.created_at) BETWEEN ? AND ?
         GROUP BY 1 ORDER BY collected DESC",
        [$r['start'], $r['end']]
    );
    foreach ($rows as &$row) {
        if ($dim === 'method') $row['label'] = PAYMENT_METHODS[$row['label']] ?? $row['label'];
        $row['payments'] = (int) $row['payments'];
        $row['collected'] = round((float) $row['collected'], 2);
    }
    return $rows;
}
