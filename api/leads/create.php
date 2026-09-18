<?php
/**
 * POST /api/leads/create.php
 * Public endpoint for the booking, callback and contact forms.
 * Responds with JSON for AJAX requests; falls back to redirects when JavaScript is off.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$fail = function (string $message, int $status, array $errors = []) use ($wantsJson): void {
    if ($wantsJson) {
        json_response(false, $message, [], $status, $errors);
    }
    flash_set('form_error', $errors ? implode(' ', $errors) : $message);
    redirect(url('book-service'));
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!csrf_verify()) {
    $fail('Your session has expired. Please refresh the page and try again.', 419);
}

$formType = in_array($_POST['form_type'] ?? '', LEAD_FORM_TYPES, true) ? $_POST['form_type'] : 'booking';

// Honeypot: bots fill hidden fields. Pretend success, store nothing.
if (!empty($_POST['website'])) {
    $wantsJson ? json_response(true, 'Thank you! We will call you shortly.', ['redirect' => url('thank-you')]) : redirect(url('thank-you'));
}

if (!rate_limit('lead_submit_ip', client_ip(), 10, 3600)) {
    $fail('Too many requests from your network. Please call us directly.', 429);
}

[$data, $errors] = lead_validate($_POST, $formType);
if ($errors) {
    $fail('Please check the highlighted fields.', 422, $errors);
}

if (!rate_limit('lead_submit_phone', $data['phone'], 5, 3600)) {
    $fail('We have already received your request. Our team will call you shortly.', 429);
}

$result = lead_submit($data);

if ($formType === 'contact') {
    db_insert('contact_submissions', [
        'name'       => $data['customer_name'],
        'phone'      => $data['phone'],
        'email'      => $data['email'],
        'subject'    => $data['subject'],
        'message'    => $data['description'],
        'lead_id'    => $result['lead_id'],
        'ip_address' => $data['ip_address'],
        'user_agent' => $data['user_agent'],
    ]);
}

$_SESSION['thank_you'] = [
    'lead_number' => $result['lead_number'],
    'name'        => $data['customer_name'],
    'service'     => $data['service_name'],
    'date'        => $data['preferred_date'],
    'time'        => $data['preferred_time'],
    'form_type'   => $formType,
    'is_existing' => $result['is_existing'],
];

$message = $result['is_existing']
    ? 'We already have your request (' . $result['lead_number'] . '). Our team will call you shortly.'
    : 'Request received! Your reference number is ' . $result['lead_number'] . '.';

if ($wantsJson) {
    json_response(true, $message, ['lead_number' => $result['lead_number'], 'redirect' => url('thank-you')], $result['is_existing'] ? 200 : 201);
}
redirect(url('thank-you'));
