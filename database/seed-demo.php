<?php
/**
 * Demo data for trying out the CRM locally. CLI only:
 *   php database/seed-demo.php
 * Creates ~60 leads over the last 30 days, a few technicians, bookings, payments and follow-ups.
 * Demo leads use phone numbers starting with 70000 so they are easy to find and delete.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__) . '/includes/bootstrap.php';

mt_srand(42);
$pick = fn(array $a) => $a[array_rand($a)];
$services = get_services();
$areas = get_locations()[0]['areas'] ?? [];
$names = ['Rahul Verma', 'Pooja Sharma', 'Amit Jain', 'Sneha Gupta', 'Vikas Meena', 'Kavita Singh', 'Deepak Agarwal', 'Ritu Choudhary',
          'Manoj Saini', 'Anjali Mathur', 'Suresh Yadav', 'Neelam Khandelwal', 'Arjun Rathore', 'Megha Bansal', 'Rohit Kumawat'];
$sources = ['google', 'google', 'google', 'facebook', 'instagram', 'whatsapp', 'direct', 'direct', 'referral', 'website'];
$weights = ['ac-repair' => 6, 'ac-service' => 4, 'ac-gas-filling' => 3, 'geyser-repair' => 3, 'chimney-cleaning' => 3, 'refrigerator-repair' => 3, 'washing-machine-repair' => 3, 'ro-repair' => 2];
$pool = [];
foreach ($services as $s) {
    for ($i = 0; $i < ($weights[$s['slug']] ?? 1); $i++) $pool[] = $s;
}

// Technicians
$techIds = [];
foreach ([['Ramesh Kumar', '7000100001', 'AC, Refrigerator'], ['Salim Khan', '7000100002', 'Chimney, Geyser'], ['Mahesh Prajapat', '7000100003', 'Washing Machine, RO'], ['Sunil Saini', '7000100004', 'AC, Electrical']] as [$n, $ph, $spec]) {
    db_query('INSERT IGNORE INTO technicians (name, phone, specialization, experience_years, status, joining_date) VALUES (?, ?, ?, ?, ?, ?)',
        [$n, $ph, $spec, mt_rand(3, 12), 'active', date('Y-m-d', strtotime('-' . mt_rand(100, 900) . ' days'))]);
    $techIds[] = (int) db_value('SELECT id FROM technicians WHERE phone = ?', [$ph]);
}

$statuses = ['new', 'new', 'contacted', 'contacted', 'follow_up', 'scheduled', 'technician_assigned', 'completed', 'completed', 'completed', 'cancelled', 'not_interested'];
$created = 0;
for ($i = 0; $i < 60; $i++) {
    $svc = $pick($pool);
    $area = $areas ? $pick($areas) : null;
    $when = date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 29) . ' days -' . mt_rand(0, 12) . ' hours'));
    $data = [
        'form_type' => mt_rand(1, 5) === 1 ? 'callback' : 'booking', 'customer_name' => $pick($names),
        'phone' => '70000' . str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT), 'email' => null,
        'category_id' => (int) $svc['category_id'], 'service_id' => (int) $svc['id'], 'service_name' => $svc['name'],
        'appliance' => null, 'brand' => null, 'problem_type' => get_service_problems()[$svc['id']][0] ?? null,
        'description' => null, 'subject' => null, 'address' => 'Demo address ' . ($i + 1), 'city' => 'Jaipur',
        'pincode' => $area['pincode'] ?? null, 'location_id' => $area['id'] ?? null,
        'preferred_date' => date('Y-m-d', strtotime($when . ' +1 day')), 'preferred_time' => time_slots()[mt_rand(0, 5)],
        'source' => $pick($sources), 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null,
        'utm_content' => null, 'gclid' => null, 'fbclid' => null, 'referrer' => null, 'landing_page' => url('services/' . $svc['slug']),
        'user_agent' => 'demo-seed', 'ip_address' => '127.0.0.1',
    ];
    $r = lead_submit($data);
    if ($r['is_existing']) continue;
    $created++;
    $leadId = $r['lead_id'];
    $status = $pick($statuses);
    db_query('UPDATE leads SET created_at = ?, last_enquiry_at = ?, status = ? WHERE id = ?', [$when, $when, $status, $leadId]);
    db_query('UPDATE lead_status_history SET created_at = ? WHERE lead_id = ?', [$when, $leadId]);
    if ($status !== 'new') {
        db_insert('lead_status_history', ['lead_id' => $leadId, 'old_status' => 'new', 'new_status' => $status, 'changed_by' => 1,
            'note' => 'Demo update', 'created_at' => date('Y-m-d H:i:s', strtotime($when . ' +2 hours'))]);
    }
    if (in_array($status, ['contacted', 'follow_up'], true)) {
        db_insert('lead_followups', ['lead_id' => $leadId, 'assigned_to' => 1, 'created_by' => 1,
            'followup_date' => date('Y-m-d', strtotime('-' . mt_rand(0, 3) . ' days')), 'followup_time' => '11:00:00',
            'type' => $pick(['call', 'whatsapp', 'call']), 'note' => 'Call back to confirm visit']);
    }
    if (in_array($status, ['scheduled', 'technician_assigned', 'completed'], true)) {
        $custId = (int) db_value('SELECT id FROM customers WHERE phone = ?', [$data['phone']]);
        if (!$custId) {
            $custId = db_insert('customers', ['customer_number' => next_number('CUS'), 'name' => $data['customer_name'], 'phone' => $data['phone'],
                'address' => $data['address'], 'city' => 'Jaipur', 'location_id' => $data['location_id'], 'source' => $data['source'], 'created_at' => $when]);
        }
        db_query('UPDATE leads SET customer_id = ?, converted_at = ? WHERE id = ?', [$custId, $when, $leadId]);
        $done = $status === 'completed';
        $amount = (float) $svc['starting_price'] + mt_rand(0, 6) * 250;
        $sched = $done ? date('Y-m-d', strtotime($when . ' +1 day')) : date('Y-m-d', strtotime('+' . mt_rand(0, 2) . ' days'));
        $bookingId = db_insert('bookings', ['booking_number' => next_number('BK'), 'lead_id' => $leadId, 'customer_id' => $custId,
            'technician_id' => $status === 'scheduled' ? null : $pick($techIds), 'service_id' => $svc['id'], 'scheduled_date' => $sched,
            'scheduled_time' => $data['preferred_time'], 'address' => $data['address'], 'city' => 'Jaipur', 'location_id' => $data['location_id'],
            'status' => $done ? 'completed' : ($status === 'scheduled' ? 'confirmed' : 'technician_assigned'),
            'estimated_amount' => $amount, 'final_amount' => $done ? $amount : null, 'amount_paid' => $done ? $amount : 0,
            'payment_status' => $done ? 'paid' : 'unpaid', 'completed_at' => $done ? $sched . ' 15:00:00' : null, 'created_by' => 1, 'created_at' => $when]);
        if ($done) {
            db_insert('payments', ['payment_number' => next_number('PAY'), 'booking_id' => $bookingId, 'customer_id' => $custId, 'amount' => $amount,
                'payment_method' => $pick(['cash', 'upi', 'upi', 'card']), 'status' => 'paid', 'payment_date' => $sched . ' 15:30:00', 'created_by' => 1]);
            db_query('UPDATE customers SET total_bookings = total_bookings + 1, total_spent = total_spent + ?, last_service_date = ? WHERE id = ?', [$amount, $sched, $custId]);
        }
    }
}
echo "Demo data created: $created leads.\n";
