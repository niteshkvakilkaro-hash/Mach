<?php
/**
 * Dashboard KPIs and chart series. Each function is one or two grouped queries.
 * Lead numbers respect row-level visibility (sales users see only their own leads).
 */

function dash_lead_kpis(): array
{
    [$vis, $p] = lead_visibility('l');
    $row = db_one(
        "SELECT
            COUNT(*)                                                         AS total,
            SUM(l.status = 'new')                                            AS status_new,
            SUM(DATE(l.created_at) = CURDATE())                              AS today,
            SUM(l.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01'))          AS month_total,
            SUM(l.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND s.is_won = 1) AS month_won
         FROM leads l JOIN lead_statuses s ON s.slug = l.status
         WHERE l.deleted_at IS NULL $vis",
        $p
    );
    $row = array_map('intval', $row);

    // Status moves made today (distinct leads), e.g. how many were contacted today
    $moves = db_all(
        "SELECT h.new_status, COUNT(DISTINCT h.lead_id) AS n
         FROM lead_status_history h JOIN leads l ON l.id = h.lead_id
         WHERE h.created_at >= CURDATE() AND h.old_status IS NOT NULL AND l.deleted_at IS NULL $vis
         GROUP BY h.new_status",
        $p
    );
    $row['today_moves'] = array_map('intval', array_column($moves, 'n', 'new_status'));
    $row['conversion'] = $row['month_total'] ? round($row['month_won'] * 100 / $row['month_total'], 1) : 0.0;
    return $row;
}

function dash_followup_kpis(): array
{
    [$vis, $p] = lead_visibility('l');
    $row = db_one(
        "SELECT SUM(f.followup_date <= CURDATE()) AS due,
                SUM(f.followup_date < CURDATE() OR (f.followup_date = CURDATE() AND f.followup_time < CURTIME())) AS overdue
         FROM lead_followups f JOIN leads l ON l.id = f.lead_id
         WHERE f.status = 'pending' AND l.deleted_at IS NULL $vis",
        $p
    );
    return ['due' => (int) $row['due'], 'overdue' => (int) $row['overdue']];
}

function dash_overdue_followups(int $limit = 6): array
{
    [$vis, $p] = lead_visibility('l');
    return db_all(
        "SELECT f.followup_date, f.followup_time, f.type, f.note, l.lead_number, l.customer_name, l.phone, u.name AS assignee
         FROM lead_followups f
         JOIN leads l ON l.id = f.lead_id
         LEFT JOIN users u ON u.id = f.assigned_to
         WHERE f.status = 'pending' AND f.followup_date <= CURDATE() AND l.deleted_at IS NULL $vis
         ORDER BY f.followup_date, f.followup_time LIMIT " . max(1, $limit),
        $p
    );
}

function dash_booking_kpis(): array
{
    $row = db_one(
        "SELECT
            SUM(b.scheduled_date = CURDATE() AND s.is_closed = 0)                   AS today,
            SUM(b.technician_id IS NOT NULL AND s.is_closed = 0)                     AS assigned_open,
            SUM(b.status = 'completed')                                              AS completed,
            SUM(b.status = 'cancelled')                                              AS cancelled,
            SUM(b.status = 'completed' AND b.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_completed,
            SUM(b.status = 'completed' AND DATE(b.completed_at) = CURDATE())        AS today_completed
         FROM bookings b JOIN booking_statuses s ON s.slug = b.status
         WHERE b.deleted_at IS NULL"
    );
    return array_map('intval', $row);
}

function dash_revenue_kpis(): array
{
    $row = db_one(
        "SELECT COALESCE(SUM(amount), 0) AS total,
                COALESCE(SUM(IF(payment_date >= CURDATE(), amount, 0)), 0) AS today,
                COALESCE(SUM(IF(payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), amount, 0)), 0) AS month,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'pending') AS pending
         FROM payments WHERE status = 'paid'"
    );
    return array_map('floatval', $row);
}

function dash_customer_count(): int
{
    return (int) db_value('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL');
}

/** Leads per day for the last $days days, zero-filled. */
function dash_leads_by_day(int $days = 30): array
{
    [$vis, $p] = lead_visibility('l');
    $rows = db_all(
        "SELECT DATE(l.created_at) AS d, COUNT(*) AS n FROM leads l
         WHERE l.deleted_at IS NULL AND l.created_at >= (CURDATE() - INTERVAL ? DAY) $vis
         GROUP BY DATE(l.created_at)",
        array_merge([$days - 1], $p)
    );
    $map = array_column($rows, 'n', 'd');
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['label' => date('d M', strtotime($d)), 'value' => (int) ($map[$d] ?? 0)];
    }
    return $out;
}

/** Top N groups by lead count in the last $days days; the rest folded into "Other". */
function dash_leads_grouped(string $by, int $days = 30, int $top = 7): array
{
    [$vis, $p] = lead_visibility('l');
    if ($by === 'service') {
        $sql = "SELECT COALESCE(s.name, 'Not specified') AS label, COUNT(*) AS n
                FROM leads l LEFT JOIN services s ON s.id = l.service_id
                WHERE l.deleted_at IS NULL AND l.created_at >= (CURDATE() - INTERVAL ? DAY) $vis
                GROUP BY 1 ORDER BY n DESC";
    } else {
        $sql = "SELECT l.source AS label, COUNT(*) AS n FROM leads l
                WHERE l.deleted_at IS NULL AND l.created_at >= (CURDATE() - INTERVAL ? DAY) $vis
                GROUP BY l.source ORDER BY n DESC";
    }
    $rows = db_all($sql, array_merge([$days - 1], $p));
    $out = [];
    $other = 0;
    foreach ($rows as $i => $r) {
        $label = $by === 'source' ? (LEAD_SOURCES[$r['label']] ?? ucfirst((string) $r['label'])) : $r['label'];
        if ($i < $top) {
            $out[] = ['label' => $label, 'value' => (int) $r['n']];
        } else {
            $other += (int) $r['n'];
        }
    }
    if ($other) {
        $out[] = ['label' => 'Other', 'value' => $other];
    }
    return $out;
}

/** Paid revenue per month for the last $months months, zero-filled. */
function dash_revenue_by_month(int $months = 6): array
{
    $rows = db_all(
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, SUM(amount) AS total FROM payments
         WHERE status = 'paid' AND payment_date >= DATE_FORMAT(CURDATE() - INTERVAL ? MONTH, '%Y-%m-01')
         GROUP BY m",
        [$months - 1]
    );
    $map = array_column($rows, 'total', 'm');
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $out[] = ['label' => date('M Y', strtotime($m . '-01')), 'value' => round((float) ($map[$m] ?? 0))];
    }
    return $out;
}

/** Per-technician job counts (open, completed this month, cancelled this month). */
function dash_technician_performance(int $limit = 8): array
{
    return db_all(
        "SELECT t.name, t.status,
                SUM(bs.is_closed = 0)                                                             AS pending,
                SUM(b.status = 'completed' AND b.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS completed,
                SUM(b.scheduled_date = CURDATE() AND bs.is_closed = 0)                            AS today,
                COALESCE(SUM(IF(b.status = 'completed' AND b.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), b.final_amount, 0)), 0) AS revenue
         FROM technicians t
         LEFT JOIN bookings b ON b.technician_id = t.id AND b.deleted_at IS NULL
         LEFT JOIN booking_statuses bs ON bs.slug = b.status
         WHERE t.deleted_at IS NULL AND t.status <> 'inactive'
         GROUP BY t.id ORDER BY completed DESC, pending DESC, t.name LIMIT " . max(1, $limit)
    );
}

/** Latest lead activity (status changes incl. creation) with who did it. */
function dash_recent_activity(int $limit = 10): array
{
    [$vis, $p] = lead_visibility('l');
    return db_all(
        "SELECT h.created_at, h.old_status, h.new_status, h.note, l.lead_number, l.customer_name,
                u.name AS user_name, ns.label AS new_label, ns.color
         FROM lead_status_history h
         JOIN leads l ON l.id = h.lead_id
         JOIN lead_statuses ns ON ns.slug = h.new_status
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE l.deleted_at IS NULL $vis
         ORDER BY h.id DESC LIMIT " . max(1, $limit),
        $p
    );
}

function dash_latest_leads(int $limit = 6): array
{
    [$vis, $p] = lead_visibility('l');
    return db_all(
        "SELECT l.lead_number, l.customer_name, l.phone, l.city, l.source, l.form_type, l.priority, l.created_at,
                s.name AS service_name, st.label AS status_label, st.color AS status_color
         FROM leads l
         LEFT JOIN services s ON s.id = l.service_id
         JOIN lead_statuses st ON st.slug = l.status
         WHERE l.deleted_at IS NULL $vis
         ORDER BY l.id DESC LIMIT " . max(1, $limit),
        $p
    );
}
