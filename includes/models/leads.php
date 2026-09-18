<?php
/**
 * Lead capture: validation, source detection, duplicate handling and creation.
 * Used by the public forms today and by the admin "create lead" screen later.
 */

const LEAD_SOURCES = [
    'website'   => 'Website',
    'google'    => 'Google',
    'facebook'  => 'Facebook',
    'instagram' => 'Instagram',
    'whatsapp'  => 'WhatsApp',
    'referral'  => 'Referral',
    'direct'    => 'Direct',
    'phone'     => 'Phone Call',
    'walk_in'   => 'Walk-in',
    'other'     => 'Other',
];

const LEAD_FORM_TYPES = ['booking', 'callback', 'contact'];

/**
 * Row-level lead visibility for the current staff user.
 * Users without `leads.view_all` only see leads assigned to them.
 * @return array{0: string, 1: array} SQL fragment starting with " AND", and its params
 */
function lead_visibility(string $alias = 'l'): array
{
    if (can('leads.view_all')) {
        return ['', []];
    }
    return [" AND {$alias}.assigned_to = ?", [auth_id() ?? 0]];
}

/** Work out the marketing source from UTM tags, click IDs and the external referrer. */
function detect_lead_source(array $attr): string
{
    $utm = strtolower((string) ($attr['utm_source'] ?? ''));
    if ($utm !== '') {
        $map = [
            'google' => 'google', 'adwords' => 'google', 'gads' => 'google',
            'facebook' => 'facebook', 'fb' => 'facebook', 'meta' => 'facebook',
            'instagram' => 'instagram', 'ig' => 'instagram',
            'whatsapp' => 'whatsapp', 'wa' => 'whatsapp',
            'referral' => 'referral', 'refer' => 'referral',
        ];
        if (isset($map[$utm])) {
            return $map[$utm];
        }
        foreach ($map as $needle => $source) {
            if (strlen($needle) > 3 && str_contains($utm, $needle)) {
                return $source;
            }
        }
        return 'other';
    }
    if (!empty($attr['gclid'])) {
        return 'google';
    }
    if (!empty($attr['fbclid'])) {
        return 'facebook';
    }
    if (!empty($attr['ref'])) {
        return 'referral';
    }

    $referrer = (string) ($attr['referrer'] ?? '');
    $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
    if ($host === '') {
        return ($attr['landing_page'] ?? '') !== '' ? 'direct' : setting('default_lead_source', 'website');
    }
    if ($host === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))) {
        return 'website';
    }
    foreach (['google.' => 'google', 'instagram.' => 'instagram', 'facebook.' => 'facebook', 'fb.' => 'facebook',
              'whatsapp.' => 'whatsapp', 'wa.me' => 'whatsapp'] as $needle => $source) {
        if (str_contains($host, $needle)) {
            return $source;
        }
    }
    return 'referral';
}

/**
 * Validate a public form submission.
 * @return array{0: array, 1: array<string,string>} [clean data, field errors]
 */
function lead_validate(array $in, string $formType): array
{
    $errors = [];
    $isBooking = $formType === 'booking';
    $isContact = $formType === 'contact';

    $name = clean_str($in['customer_name'] ?? '', 80);
    if (($isBooking || $isContact) && mb_strlen($name) < 2) {
        $errors['customer_name'] = 'Please enter your name.';
    }

    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null) {
        $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    }

    $email = clean_str($in['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address or leave it blank.';
    }

    // Service & category must exist and be active
    $services = array_column(get_services(), null, 'id');
    $serviceId = (int) ($in['service_id'] ?? 0);
    $service = $services[$serviceId] ?? null;
    if ($isBooking && !$service) {
        $errors['service_id'] = 'Please choose a service.';
    }
    $categoryId = $service ? (int) $service['category_id'] : (int) ($in['category_id'] ?? 0);
    if ($categoryId && !in_array($categoryId, array_map('intval', array_column(get_categories(), 'id')), true)) {
        $categoryId = 0;
    }

    $address = clean_str($in['address'] ?? '', 500);
    if ($isBooking && mb_strlen($address) < 5) {
        $errors['address'] = 'Please enter your full address.';
    }

    $city = clean_str($in['city'] ?? '', 80);
    if ($isBooking && $city === '') {
        $errors['city'] = 'Please select your city.';
    }

    // Area must belong to the configured locations
    $locationId = (int) ($in['location_id'] ?? 0);
    $validAreas = [];
    foreach (get_locations() as $c) {
        foreach ($c['areas'] as $a) {
            $validAreas[(int) $a['id']] = true;
        }
    }
    if (!isset($validAreas[$locationId])) {
        $locationId = 0;
    }

    $pincode = clean_str($in['pincode'] ?? '', 10);
    if ($pincode !== '' && !preg_match('/^[1-9]\d{5}$/', $pincode)) {
        $errors['pincode'] = 'Enter a valid 6-digit pincode.';
    }

    $date = clean_str($in['preferred_date'] ?? '', 10);
    if ($date !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        $today = new DateTime('today');
        $max = (new DateTime('today'))->modify('+' . (int) setting('booking_max_days_ahead', '30') . ' days');
        if (!$d || $d->format('Y-m-d') !== $date || $d < $today || $d > $max) {
            $errors['preferred_date'] = 'Choose a date between today and ' . $max->format('d M') . '.';
        }
    } elseif ($isBooking) {
        $errors['preferred_date'] = 'Please choose a preferred date.';
    }

    $time = clean_str($in['preferred_time'] ?? '', 40);
    if ($time !== '' && !in_array($time, time_slots(), true)) {
        $errors['preferred_time'] = 'Please choose a time slot.';
    } elseif ($time === '' && $isBooking) {
        $errors['preferred_time'] = 'Please choose a time slot.';
    }

    $description = clean_str($in['description'] ?? '', 1000);
    if ($isContact && mb_strlen($description) < 5) {
        $errors['description'] = 'Please write a short message.';
    }

    if (($isBooking || $isContact) && empty($in['consent'])) {
        $errors['consent'] = 'Please accept to continue.';
    }

    $attr = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $attr[$k] = clean_str($in[$k] ?? '', 150);
    }
    $attr['gclid'] = clean_str($in['gclid'] ?? '', 255);
    $attr['fbclid'] = clean_str($in['fbclid'] ?? '', 255);
    $attr['ref'] = clean_str($in['ref'] ?? '', 100);
    $attr['referrer'] = clean_str($in['referrer'] ?? '', 500);
    $attr['landing_page'] = clean_str($in['landing_page'] ?? '', 500);

    $data = [
        'form_type'      => $formType,
        'customer_name'  => $name,
        'phone'          => $phone,
        'email'          => $email ?: null,
        'category_id'    => $categoryId ?: null,
        'service_id'     => $service ? $serviceId : null,
        'service_name'   => $service['name'] ?? null,
        'appliance'      => clean_str($in['appliance'] ?? '', 100) ?: null,
        'brand'          => clean_str($in['brand'] ?? '', 60) ?: null,
        'problem_type'   => clean_str($in['problem_type'] ?? '', 150) ?: null,
        'description'    => $description ?: null,
        'subject'        => clean_str($in['subject'] ?? '', 150) ?: null,
        'address'        => $address ?: null,
        'city'           => $city ?: null,
        'pincode'        => $pincode ?: null,
        'location_id'    => $locationId ?: null,
        'preferred_date' => $date ?: null,
        'preferred_time' => $time ?: null,
        'source'         => detect_lead_source($attr),
        'utm_source'     => $attr['utm_source'] ?: null,
        'utm_medium'     => $attr['utm_medium'] ?: null,
        'utm_campaign'   => $attr['utm_campaign'] ?: null,
        'utm_term'       => $attr['utm_term'] ?: null,
        'utm_content'    => $attr['utm_content'] ?: null,
        'gclid'          => $attr['gclid'] ?: null,
        'fbclid'         => $attr['fbclid'] ?: null,
        'referrer'       => $attr['referrer'] ?: null,
        'landing_page'   => $attr['landing_page'] ?: null,
        'user_agent'     => clean_str($_SERVER['HTTP_USER_AGENT'] ?? '', 500) ?: null,
        'ip_address'     => client_ip(),
    ];

    return [$data, $errors];
}

/**
 * Save a validated submission.
 * If the phone already has an OPEN lead for the same service, the submission is merged into it
 * (enquiry_count + 1, stored in lead_enquiries) instead of creating a duplicate.
 *
 * @return array{lead_id:int, lead_number:string, is_existing:bool}
 */
function lead_submit(array $data): array
{
    $result = db_transaction(function () use ($data) {
        $existing = db_one(
            'SELECT l.id, l.lead_number FROM leads l
             JOIN lead_statuses s ON s.slug = l.status
             WHERE l.phone = ? AND l.deleted_at IS NULL AND s.is_closed = 0
               AND (l.service_id <=> ? OR l.service_id IS NULL OR ? IS NULL)
             ORDER BY l.id DESC LIMIT 1 FOR UPDATE',
            [$data['phone'], $data['service_id'], $data['service_id']]
        );

        if ($existing) {
            $leadId = (int) $existing['id'];
            db_query(
                'UPDATE leads SET enquiry_count = enquiry_count + 1, last_enquiry_at = NOW(),
                    customer_name = IF(customer_name = \'\', ?, customer_name),
                    email = COALESCE(email, ?), service_id = COALESCE(service_id, ?),
                    category_id = COALESCE(category_id, ?), address = COALESCE(address, ?),
                    city = COALESCE(city, ?), pincode = COALESCE(pincode, ?), location_id = COALESCE(location_id, ?)
                 WHERE id = ?',
                [$data['customer_name'], $data['email'], $data['service_id'], $data['category_id'], $data['address'],
                 $data['city'], $data['pincode'], $data['location_id'], $leadId]
            );
            lead_record_enquiry($leadId, $data);
            return ['lead_id' => $leadId, 'lead_number' => $existing['lead_number'], 'is_existing' => true];
        }

        $customerId = db_value('SELECT id FROM customers WHERE phone = ? AND deleted_at IS NULL', [$data['phone']]);
        $hasHistory = $customerId || db_value('SELECT 1 FROM leads WHERE phone = ? LIMIT 1', [$data['phone']]);
        $status = setting('default_lead_status', 'new');
        $leadNumber = next_number('LEAD');

        $leadId = db_insert('leads', [
            'lead_number'        => $leadNumber,
            'customer_id'        => $customerId ?: null,
            'customer_name'      => $data['customer_name'],
            'phone'              => $data['phone'],
            'email'              => $data['email'],
            'category_id'        => $data['category_id'],
            'service_id'         => $data['service_id'],
            'appliance'          => $data['appliance'],
            'brand'              => $data['brand'],
            'problem_type'       => $data['problem_type'],
            'description'        => $data['form_type'] === 'contact' && $data['subject']
                                        ? $data['subject'] . "\n\n" . $data['description']
                                        : $data['description'],
            'address'            => $data['address'],
            'city'               => $data['city'],
            'pincode'            => $data['pincode'],
            'location_id'        => $data['location_id'],
            'preferred_date'     => $data['preferred_date'],
            'preferred_time'     => $data['preferred_time'],
            'form_type'          => $data['form_type'],
            'source'             => $data['source'],
            'utm_source'         => $data['utm_source'],
            'utm_medium'         => $data['utm_medium'],
            'utm_campaign'       => $data['utm_campaign'],
            'utm_term'           => $data['utm_term'],
            'utm_content'        => $data['utm_content'],
            'gclid'              => $data['gclid'],
            'fbclid'             => $data['fbclid'],
            'landing_page'       => $data['landing_page'],
            'referrer'           => $data['referrer'],
            'user_agent'         => $data['user_agent'],
            'ip_address'         => $data['ip_address'],
            'status'             => $status,
            'priority'           => $data['form_type'] === 'callback' ? 'high' : 'normal',
            'is_repeat_customer' => $hasHistory ? 1 : 0,
            'last_enquiry_at'    => date('Y-m-d H:i:s'),
            'consent_at'         => date('Y-m-d H:i:s'),
        ]);

        db_insert('lead_status_history', [
            'lead_id'    => $leadId,
            'old_status' => null,
            'new_status' => $status,
            'note'       => 'Lead created from website (' . $data['form_type'] . ' form)',
        ]);
        lead_record_enquiry($leadId, $data);

        if (setting('lead_auto_assign') === '1' && ($userId = lead_pick_sales_user())) {
            db_query('UPDATE leads SET assigned_to = ? WHERE id = ?', [$userId, $leadId]);
            db_insert('lead_assignments', [
                'lead_id' => $leadId, 'assignment_type' => 'sales', 'assigned_to' => $userId, 'note' => 'Auto-assigned',
            ]);
            notify_user($userId, 'lead.assigned', "New lead assigned: $leadNumber", lead_summary($data), 'admin/leads/view?number=' . $leadNumber);
        }

        return ['lead_id' => $leadId, 'lead_number' => $leadNumber, 'is_existing' => false];
    });

    // Notifications are outside the transaction: a failure here must not lose the lead.
    try {
        $title = $result['is_existing']
            ? "Repeat enquiry on {$result['lead_number']}"
            : ($data['form_type'] === 'callback' ? 'Callback request' : 'New lead') . ": {$result['lead_number']}";
        notify_roles(['super_admin', 'admin', 'manager'], $result['is_existing'] ? 'lead.repeat' : 'lead.new',
            $title, lead_summary($data), 'admin/leads/view?number=' . $result['lead_number']);
    } catch (Throwable $e) {
        app_log('warning', 'Lead notification failed: ' . $e->getMessage(), ['lead' => $result['lead_number']]);
    }
    mail_event('mail_event_new_lead', $data, $result);
    mail_event('mail_event_lead_confirmation', $data, $result);

    return $result;
}

function lead_record_enquiry(int $leadId, array $data): void
{
    db_insert('lead_enquiries', [
        'lead_id'        => $leadId,
        'service_id'     => $data['service_id'],
        'form_type'      => $data['form_type'],
        'source'         => $data['source'],
        'problem_type'   => $data['problem_type'],
        'message'        => $data['description'],
        'preferred_date' => $data['preferred_date'],
        'preferred_time' => $data['preferred_time'],
        'landing_page'   => $data['landing_page'],
        'ip_address'     => $data['ip_address'],
    ]);
}

/** Active sales user with the fewest open leads (simple round-robin by load). */
function lead_pick_sales_user(): ?int
{
    $id = db_value(
        "SELECT u.id FROM users u
         JOIN roles r ON r.id = u.role_id AND r.slug = 'sales'
         LEFT JOIN leads l ON l.assigned_to = u.id AND l.deleted_at IS NULL
              AND l.status IN (SELECT slug FROM lead_statuses WHERE is_closed = 0)
         WHERE u.status = 'active' AND u.deleted_at IS NULL
         GROUP BY u.id ORDER BY COUNT(l.id), u.id LIMIT 1"
    );
    return $id ? (int) $id : null;
}

function lead_summary(array $data): string
{
    $parts = array_filter([
        $data['customer_name'] ?: null,
        $data['service_name'] ?? null,
        $data['city'] ?? null,
        $data['preferred_date'] ? date('d M', strtotime($data['preferred_date'])) . ($data['preferred_time'] ? ', ' . $data['preferred_time'] : '') : null,
    ]);
    return implode(' · ', $parts);
}
