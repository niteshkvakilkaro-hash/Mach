<?php
/**
 * Email: SMTP client (no external library), outgoing queue and branded templates.
 *
 * Flow: an event (new lead, booking, payment) calls mail_queue() → a row in email_queue.
 * The queue is sent after the response (shutdown), on admin page loads, and by cron/send-emails.php,
 * so a slow or broken mail server never slows down or breaks the website.
 */

// ================================================================ secrets (SMTP password at rest)

function secret_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $key = base64_decode((string) config('app.app_key'), true);
    if (!$key || strlen($key) < 32) throw new RuntimeException('config/app.php app_key is missing.');
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4), true);
    $key = base64_decode((string) config('app.app_key'), true);
    if ($raw === false || strlen($raw) < 29 || !$key) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', substr($key, 0, 32), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

// ================================================================ configuration

/** SMTP settings, read fresh from the database (settings() is cached per request, which is fine). */
function mail_config(): array
{
    return [
        'enabled'    => setting('mail_enabled') === '1',
        'host'       => setting('smtp_host'),
        'port'       => (int) setting('smtp_port', '587'),
        'encryption' => setting('smtp_encryption', 'tls'),            // tls (STARTTLS) | ssl | none
        'username'   => setting('smtp_username'),
        'password'   => secret_decrypt(setting('smtp_password')),
        'from_email' => setting('mail_from_email', setting('smtp_username')),
        'from_name'  => setting('mail_from_name', setting('business_name')),
        'reply_to'   => setting('mail_reply_to'),
    ];
}

function mail_ready(): bool
{
    $c = mail_config();
    return $c['enabled'] && $c['host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL);
}

// ================================================================ SMTP client

final class SmtpClient
{
    /** @var resource|null */
    private $socket;
    private array $log = [];

    public function __construct(private array $cfg, private int $timeout = 20) {}

    /** Send one message. Throws RuntimeException with the server's reply on failure. */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): void
    {
        $c = $this->cfg;
        $host = ($c['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host'], 'SNI_enabled' => true]]);
        $this->socket = @stream_socket_client($host, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            throw new RuntimeException("Could not connect to {$c['host']}:{$c['port']} — $errstr ($errno). Check host, port and encryption.");
        }
        stream_set_timeout($this->socket, $this->timeout);
        try {
            $this->expect([220]);
            $ehloHost = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
            $this->cmd("EHLO $ehloHost", [250]);
            if ($c['encryption'] === 'tls') {
                $this->cmd('STARTTLS', [220]);
                $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if (!$ok) throw new RuntimeException('TLS handshake failed. Try encryption "SSL" with port 465.');
                $this->cmd("EHLO $ehloHost", [250]);
            }
            if ($c['username'] !== '') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($c['username']), [334], 'username');
                $this->cmd(base64_encode($c['password']), [235], 'password');
            }
            $this->cmd('MAIL FROM:<' . $c['from_email'] . '>', [250]);
            $this->cmd('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            $this->write($this->buildMessage($toEmail, $toName, $subject, $html, $text) . "\r\n.");
            $this->expect([250]);
            $this->cmd('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) fclose($this->socket);
        }
    }

    private function cmd(string $line, array $codes, string $mask = ''): void
    {
        $this->write($line);
        $this->expect($codes, $mask ? "[$mask]" : $line);
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data . "\r\n") === false) throw new RuntimeException('Connection to mail server lost.');
    }

    /** Read a (possibly multi-line) reply and check its code. */
    private function expect(array $codes, string $after = 'connect'): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $meta = stream_get_meta_data($this->socket);
        if ($reply === '' || $meta['timed_out']) throw new RuntimeException("Mail server did not answer (after $after).");
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            $hint = $code === 535 ? ' — wrong username or password (Gmail needs an App Password).' : '';
            throw new RuntimeException("Mail server said: " . trim(preg_replace('/\s+/', ' ', $reply)) . $hint);
        }
        return $reply;
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $html, string $text): string
    {
        $c = $this->cfg;
        $enc = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $addr = fn(string $email, string $name) => $name !== '' ? $enc(str_replace(['"', "\r", "\n"], '', $name)) . " <$email>" : $email;
        $domain = substr(strrchr($c['from_email'], '@'), 1) ?: 'localhost';
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $addr($c['from_email'], $c['from_name']),
            'To: ' . $addr($toEmail, $toName),
            'Subject: ' . $enc(str_replace(["\r", "\n"], ' ', $subject)),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: MACH-CRM',
        ];
        if ($c['reply_to'] !== '' && filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $c['reply_to'];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . "--$boundary--";
        // Dot-stuffing: a line starting with "." must be doubled (base64 never starts with ".", headers don't either)
        return preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }
}

// ================================================================ queue

/** Put an email in the queue. Returns false (and does nothing) when email is off or the address is invalid. */
function mail_queue(string $toEmail, string $toName, string $subject, string $html, string $text, string $event, ?string $related = null): bool
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !mail_ready()) return false;
    db_insert('email_queue', [
        'to_email' => mb_substr($toEmail, 0, 150), 'to_name' => mb_substr($toName, 0, 100) ?: null, 'subject' => mb_substr($subject, 0, 200),
        'html_body' => $html, 'text_body' => $text, 'event' => $event, 'related' => $related,
    ]);
    mail_process_after_response();
    return true;
}

/** Send queued mail once this request has finished (non-blocking on LiteSpeed / PHP-FPM). */
function mail_process_after_response(): void
{
    static $registered = false;
    if ($registered) return;
    $registered = true;
    register_shutdown_function(function () {
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            mail_process_queue(5);
        } catch (Throwable $e) {
            app_log('error', 'Mail queue: ' . $e->getMessage());
        }
    });
}

/**
 * Send up to $limit due emails. Each row is claimed atomically, so parallel runs never double-send.
 * Failures retry after 5, 15 and 60 minutes, then stay "failed".
 * @return array{sent:int, failed:int}
 */
function mail_process_queue(int $limit = 20): array
{
    $stats = ['sent' => 0, 'failed' => 0];
    if (!mail_ready()) return $stats;
    db_query("UPDATE email_queue SET status = 'pending' WHERE status = 'sending' AND locked_at < NOW() - INTERVAL 10 MINUTE");
    $ids = array_column(db_all("SELECT id FROM email_queue WHERE status = 'pending' AND send_after <= NOW() ORDER BY id LIMIT " . max(1, $limit)), 'id');
    $client = new SmtpClient(mail_config());
    foreach ($ids as $id) {
        if (!db_query("UPDATE email_queue SET status = 'sending', locked_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status = 'pending'", [$id])->rowCount()) continue;
        $m = db_one('SELECT * FROM email_queue WHERE id = ?', [$id]);
        try {
            $client->send($m['to_email'], (string) $m['to_name'], $m['subject'], $m['html_body'], $m['text_body']);
            db_query("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?", [$id]);
            $stats['sent']++;
        } catch (Throwable $e) {
            $delay = [1 => 5, 2 => 15, 3 => 60][(int) $m['attempts']] ?? null;
            db_query('UPDATE email_queue SET status = ?, last_error = ?, send_after = IF(? IS NULL, send_after, NOW() + INTERVAL ? MINUTE) WHERE id = ?',
                [$delay ? 'pending' : 'failed', mb_substr($e->getMessage(), 0, 500), $delay, (int) $delay, $id]);
            app_log('warning', 'Email failed', ['id' => $id, 'to' => $m['to_email'], 'error' => $e->getMessage()]);
            $stats['failed']++;
        }
    }
    return $stats;
}

/** Send immediately (used by "Send test email"). Throws with a readable error. */
function mail_send_now(string $toEmail, string $subject, string $html, string $text): void
{
    $cfg = mail_config();
    if ($cfg['host'] === '') throw new RuntimeException('Enter the SMTP host first.');
    if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid "From" email address.');
    (new SmtpClient($cfg))->send($toEmail, '', $subject, $html, $text);
}

// ================================================================ templates

/**
 * Branded, email-client-safe HTML (tables + inline styles, light theme).
 * $rows: [label => value] pairs; all values are escaped here.
 */
function mail_template(string $heading, string $intro, array $rows = [], ?array $button = null, string $footerNote = ''): array
{
    $brand = setting('business_name', 'Home Services');
    $rowsHtml = '';
    $rowsText = '';
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') continue;
        $rowsHtml .= '<tr><td style="padding:8px 0;color:#6b6485;font-size:14px;width:40%;vertical-align:top">' . e($label) . '</td>'
            . '<td style="padding:8px 0;color:#1b1530;font-size:14px;font-weight:600;vertical-align:top">' . nl2br(e((string) $value)) . '</td></tr>';
        $rowsText .= "$label: $value\n";
    }
    $btnHtml = $button ? '<p style="margin:28px 0 8px"><a href="' . e($button[1]) . '" style="display:inline-block;background:#7c5cf0;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px;font-size:15px">' . e($button[0]) . '</a></p>' : '';
    $contact = implode(' · ', array_filter([setting('phone'), setting('email')]));
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f2fa;font-family:Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2fa;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden">'
        . '<tr><td style="background:linear-gradient(90deg,#b78bff,#f472b6,#fdba74);background-color:#b78bff;height:6px;font-size:0;line-height:0">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 28px 8px"><div style="font-size:18px;font-weight:800;color:#1b1530">' . e($brand) . '</div></td></tr>'
        . '<tr><td style="padding:8px 28px 28px">'
        . '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#1b1530">' . e($heading) . '</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3d3654">' . nl2br(e($intro)) . '</p>'
        . ($rowsHtml ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #ece9f5;border-bottom:1px solid #ece9f5;margin:8px 0">' . $rowsHtml . '</table>' : '')
        . $btnHtml
        . ($footerNote ? '<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#6b6485">' . nl2br(e($footerNote)) . '</p>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#faf9fd;color:#8a84a3;font-size:12px;line-height:1.6">' . e($brand) . ($contact ? ' · ' . e($contact) : '') . '<br>' . e(setting('address')) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    $text = "$heading\n\n$intro\n\n" . $rowsText . ($button ? "\n{$button[0]}: {$button[1]}\n" : '') . ($footerNote ? "\n$footerNote\n" : '')
        . "\n--\n$brand" . ($contact ? "\n$contact" : '');
    return [$html, $text];
}

// ================================================================ events

/** New lead → staff alert to every address in "Send email alerts to" (comma separated). */
function mail_event_new_lead(array $data, array $result): void
{
    if (setting('notify_email') !== '1') return;
    $to = array_filter(array_map('trim', explode(',', setting('notify_email_to'))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    if (!$to) return;
    $kind = $result['is_existing'] ? 'Repeat enquiry' : ($data['form_type'] === 'callback' ? 'Callback request' : 'New lead');
    [$html, $text] = mail_template(
        "$kind: {$result['lead_number']}",
        ($data['customer_name'] ?: 'A customer') . ' just contacted you through the website. Call them quickly — fast replies win jobs.',
        [
            'Name' => $data['customer_name'] ?: '—', 'Phone' => $data['phone'], 'Email' => $data['email'], 'Service' => $data['service_name'],
            'Problem' => $data['problem_type'], 'Area' => trim(($data['city'] ?? '') . ($data['pincode'] ? ' ' . $data['pincode'] : '')),
            'Preferred visit' => $data['preferred_date'] ? date('D, d M Y', strtotime($data['preferred_date'])) . ($data['preferred_time'] ? ', ' . $data['preferred_time'] : '') : null,
            'Message' => $data['description'], 'Source' => LEAD_SOURCES[$data['source']] ?? $data['source'],
        ],
        ['Open lead in CRM', url('admin/leads/view?number=' . $result['lead_number'])]
    );
    foreach ($to as $email) {
        mail_queue($email, '', "$kind: {$result['lead_number']} — " . ($data['service_name'] ?: 'enquiry') . ($data['customer_name'] ? " ({$data['customer_name']})" : ''), $html, $text, 'lead.new', $result['lead_number']);
    }
}

/** Customer gave an email on the website form → "we received your request". */
function mail_event_lead_confirmation(array $data, array $result): void
{
    if (setting('email_customer_lead') !== '1' || empty($data['email']) || $result['is_existing']) return;
    [$html, $text] = mail_template(
        'We’ve received your request',
        'Hi ' . ($data['customer_name'] ? strtok($data['customer_name'], ' ') : 'there') . ', thank you for contacting ' . setting('business_name') . ". Our team will call you shortly to confirm the visit.",
        ['Reference' => $result['lead_number'], 'Service' => $data['service_name'], 'Preferred visit' => $data['preferred_date'] ? date('D, d M Y', strtotime($data['preferred_date'])) . ($data['preferred_time'] ? ', ' . $data['preferred_time'] : '') : null],
        ['Chat with us on WhatsApp', whatsapp_link('Hi, my request number is ' . $result['lead_number'] . '.')],
        'Need it urgently? Call us on ' . setting('phone') . '.'
    );
    mail_queue($data['email'], $data['customer_name'], 'Request received — ' . $result['lead_number'], $html, $text, 'lead.confirmation', $result['lead_number']);
}

/** Booking created → confirmation to the customer (if we have their email). */
function mail_event_booking_confirmation(string $bookingNumber): void
{
    if (setting('email_customer_booking') !== '1') return;
    $b = booking_find($bookingNumber);
    if (!$b || empty($b['email'])) return;
    [$html, $text] = mail_template(
        'Your visit is confirmed',
        'Hi ' . strtok($b['customer_name'], ' ') . ', your ' . ($b['service_name'] ?: 'service') . ' visit is booked. Our technician will call you before reaching.',
        ['Booking' => $b['booking_number'], 'Date' => date('l, d M Y', strtotime($b['scheduled_date'])), 'Time' => $b['scheduled_time'],
         'Address' => $b['address'], 'Technician' => $b['technician_name'], 'Estimated charge' => $b['estimated_amount'] !== null ? format_inr($b['estimated_amount']) . ' (final price after inspection)' : null],
        ['Need to change the time? WhatsApp us', whatsapp_link('Hi, I want to change my booking ' . $b['booking_number'] . '.')],
        'Please keep the appliance accessible. You pay only after the job is done.'
    );
    mail_queue($b['email'], $b['customer_name'], 'Booking confirmed — ' . date('d M', strtotime($b['scheduled_date'])) . ', ' . $b['scheduled_time'], $html, $text, 'booking.confirmation', $b['booking_number']);
}

/** Payment received → receipt to the customer. */
function mail_event_payment_receipt(string $paymentNumber): void
{
    if (setting('email_customer_receipt') !== '1') return;
    $p = payment_find($paymentNumber);
    $email = $p ? db_value('SELECT email FROM customers WHERE id = ?', [$p['customer_id']]) : null;
    if (!$p || $p['status'] !== 'paid' || !$email) return;
    $due = booking_amount_due($p);
    [$html, $text] = mail_template(
        'Payment received — thank you!',
        'Hi ' . strtok($p['customer_name'], ' ') . ', we have received your payment. Keep this email as your receipt.',
        ['Receipt no.' => $p['payment_number'], 'Amount' => format_inr($p['amount'], true), 'Paid by' => PAYMENT_METHODS[$p['payment_method']] ?? $p['payment_method'],
         'Reference' => $p['transaction_id'], 'Date' => date('d M Y, h:i A', strtotime($p['payment_date'] ?? $p['created_at'])),
         'Service' => $p['service_name'], 'Booking' => $p['booking_number'],
         'Balance due' => $due !== null ? format_inr(max(0, $due - (float) $p['amount_paid']), true) : null,
         'Warranty until' => $p['warranty_until'] ? date('d M Y', strtotime($p['warranty_until'])) : null],
        null,
        'If the same problem comes back during the warranty period, just reply to this email or call us.'
    );
    mail_queue($email, $p['customer_name'], 'Payment receipt ' . $p['payment_number'] . ' — ' . format_inr($p['amount']), $html, $text, 'payment.receipt', $p['payment_number']);
}

/** Wrap an event so an email problem can never break the action that triggered it. */
function mail_event(callable $fn, ...$args): void
{
    try {
        $fn(...$args);
    } catch (Throwable $e) {
        app_log('warning', 'Email event failed: ' . $e->getMessage());
    }
}
