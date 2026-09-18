<?php
/**
 * Business settings: contact details, branding, social links, trust numbers, SEO defaults,
 * booking slots and lead handling. Saved in the `settings` key/value table; the website reads them live.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/admin/crud.php';
$me = require_permission('settings.manage');

$groups = [
    'business' => ['title' => 'Business & contact', 'icon' => 'bi-shop', 'fields' => [
        'business_name'     => ['label' => 'Business name', 'required' => true, 'max' => 100, 'col' => 6, 'help' => 'The first word is used as the logo text when no logo is uploaded.'],
        'business_tagline'  => ['label' => 'Tagline under the logo', 'max' => 60, 'col' => 6],
        'phone'             => ['label' => 'Phone (shown on website)', 'required' => true, 'max' => 20, 'col' => 6, 'placeholder' => '+91 85028 37831'],
        'whatsapp'          => ['label' => 'WhatsApp number', 'required' => true, 'max' => 15, 'col' => 6, 'help' => 'With country code, digits only: 918502837831'],
        'email'             => ['label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 150, 'col' => 6],
        'city'              => ['label' => 'Main city', 'required' => true, 'max' => 60, 'col' => 6, 'help' => 'Used in page titles, e.g. “AC Repair in Jaipur”.'],
        'address'           => ['label' => 'Address', 'max' => 300],
        'working_hours'     => ['label' => 'Working hours', 'max' => 100, 'col' => 6, 'placeholder' => 'Mon–Sun, 8:00 AM – 9:00 PM'],
        'google_maps_url'   => ['label' => 'Google Maps link', 'type' => 'url', 'max' => 500, 'col' => 6],
        'google_maps_embed' => ['label' => 'Google Maps embed URL (contact page)', 'type' => 'url', 'max' => 1000,
                                'help' => 'Google Maps → Share → Embed a map → copy only the src link (starts with https://www.google.com/maps/embed).'],
    ]],
    'branding' => ['title' => 'Logo & images', 'icon' => 'bi-image', 'fields' => [
        'logo'     => ['label' => 'Logo', 'type' => 'image', 'folder' => 'branding', 'max_side' => 480, 'help' => 'PNG with transparent background works best. Shown at 40px height.'],
        'favicon'  => ['label' => 'Favicon (browser tab icon)', 'type' => 'image', 'folder' => 'branding', 'max_side' => 256, 'help' => 'Square image, at least 64×64.'],
        'og_image' => ['label' => 'Social share image', 'type' => 'image', 'folder' => 'branding', 'max_side' => 1200, 'help' => 'Shown when a link is shared on WhatsApp/Facebook. 1200×630 recommended.'],
    ]],
    'social' => ['title' => 'Social media', 'icon' => 'bi-share', 'fields' => [
        'social_facebook'  => ['label' => 'Facebook', 'type' => 'url', 'max' => 255, 'col' => 6],
        'social_instagram' => ['label' => 'Instagram', 'type' => 'url', 'max' => 255, 'col' => 6],
        'social_youtube'   => ['label' => 'YouTube', 'type' => 'url', 'max' => 255, 'col' => 6],
        'social_x'         => ['label' => 'X (Twitter)', 'type' => 'url', 'max' => 255, 'col' => 6],
        'social_linkedin'  => ['label' => 'LinkedIn', 'type' => 'url', 'max' => 255, 'col' => 6],
    ]],
    'trust' => ['title' => 'Trust numbers', 'icon' => 'bi-award', 'fields' => [
        'rating_value'      => ['label' => 'Average rating', 'type' => 'decimal', 'min' => 1, 'max' => 5, 'col' => 4, 'help' => 'Use your real Google rating.'],
        'rating_count'      => ['label' => 'Number of reviews', 'type' => 'int', 'min' => 0, 'col' => 4],
        'jobs_completed'    => ['label' => 'Repairs completed', 'type' => 'int', 'min' => 0, 'col' => 4],
        'years_experience'  => ['label' => 'Years in business', 'type' => 'int', 'min' => 0, 'max' => 100, 'col' => 4],
        'technicians_count' => ['label' => 'Technicians', 'type' => 'int', 'min' => 0, 'col' => 4],
    ]],
    'seo' => ['title' => 'SEO defaults', 'icon' => 'bi-search', 'fields' => [
        'seo_default_title'       => ['label' => 'Home page title', 'max' => 160],
        'seo_default_description' => ['label' => 'Home page description', 'type' => 'textarea', 'max' => 320, 'rows' => 3],
    ]],
    'leads' => ['title' => 'Booking & leads', 'icon' => 'bi-calendar-check', 'fields' => [
        'booking_time_slots'     => ['label' => 'Time slots (one per line)', 'type' => 'textarea', 'required' => true, 'max' => 1000, 'rows' => 6],
        'booking_max_days_ahead' => ['label' => 'Customers can book up to (days ahead)', 'type' => 'int', 'min' => 1, 'max' => 180, 'required' => true, 'col' => 6],
        'default_lead_status'    => ['label' => 'Status for new website leads', 'type' => 'select', 'required' => true, 'col' => 6, 'options' => fn() => array_column(lead_statuses(), 'label', 'slug')],
        'default_lead_source'    => ['label' => 'Source when it can’t be detected', 'type' => 'select', 'required' => true, 'col' => 6, 'options' => LEAD_SOURCES],
        'lead_auto_assign'       => ['label' => 'Auto-assign new leads to the sales person with the fewest open leads', 'type' => 'checkbox', 'col' => 6],
    ]],
    'quality' => ['title' => 'Feedback & complaints', 'icon' => 'bi-star-half', 'fields' => [
        'feedback_enabled'  => ['label' => 'Ask customers to rate every completed job', 'type' => 'checkbox', 'col' => 12, 'help' => 'A private rating link is created when a booking is marked Completed (emailed automatically; WhatsApp button on the booking page).'],
        'google_review_url' => ['label' => 'Google review link', 'type' => 'url', 'max' => 500, 'col' => 12, 'placeholder' => 'https://g.page/r/…/review',
                                'help' => 'Google Business Profile → “Ask for reviews” → copy link. Customers who rate 4–5★ are invited to post it on Google.'],
        'feedback_low_rating' => ['label' => 'Open a complaint automatically at or below', 'type' => 'select', 'required' => true, 'col' => 6, 'options' => ['3' => '3★ or lower (recommended)', '2' => '2★ or lower', '1' => 'Only 1★']],
        'complaint_sla_hours' => ['label' => 'Respond to complaints within (hours)', 'type' => 'int', 'min' => 1, 'max' => 72, 'required' => true, 'col' => 3],
        'complaint_sla_hours_urgent' => ['label' => 'Urgent complaints within (hours)', 'type' => 'int', 'min' => 1, 'max' => 24, 'required' => true, 'col' => 3],
    ]],
    'email' => ['title' => 'Email & notifications', 'icon' => 'bi-envelope', 'fields' => [
        'mail_enabled'    => ['label' => 'Send emails', 'type' => 'checkbox', 'col' => 12, 'help' => 'Master switch. Fill in the mail server below, save, then use “Send test email”.', 'section' => 'Mail server (SMTP)'],
        'smtp_host'       => ['label' => 'SMTP host', 'max' => 150, 'col' => 6, 'placeholder' => 'smtp.hostinger.com'],
        'smtp_port'       => ['label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'col' => 3],
        'smtp_encryption' => ['label' => 'Encryption', 'type' => 'select', 'required' => true, 'col' => 3, 'options' => ['ssl' => 'SSL (port 465)', 'tls' => 'TLS / STARTTLS (port 587)', 'none' => 'None (not recommended)']],
        'smtp_username'   => ['label' => 'Username', 'max' => 150, 'col' => 6, 'placeholder' => 'usually your full email address'],
        'smtp_password'   => ['label' => 'Password', 'type' => 'password', 'max' => 200, 'col' => 6, 'help' => 'Stored encrypted. Leave blank to keep the saved password.'],
        'mail_from_email' => ['label' => '“From” email', 'type' => 'email', 'max' => 150, 'col' => 6, 'help' => 'Must be an address your mail server allows (normally the same as the username).'],
        'mail_from_name'  => ['label' => '“From” name', 'max' => 100, 'col' => 6, 'placeholder' => 'MACH Home Services'],
        'mail_reply_to'   => ['label' => 'Reply-to email (optional)', 'type' => 'email', 'max' => 150, 'col' => 6, 'help' => 'Where customer replies go, if different from the “From” email.'],
        'notify_email'    => ['label' => 'Email the team when a new lead arrives', 'type' => 'checkbox', 'col' => 6, 'section' => 'Which emails to send'],
        'notify_email_to' => ['label' => 'Team email addresses', 'max' => 500, 'col' => 6, 'placeholder' => 'owner@gmail.com, sales@yourdomain.in', 'help' => 'Separate several addresses with commas.'],
        'email_customer_lead'    => ['label' => 'Customer: “We received your request” (when they give an email)', 'type' => 'checkbox', 'col' => 12],
        'email_customer_booking' => ['label' => 'Customer: booking confirmation with date, time and technician', 'type' => 'checkbox', 'col' => 12],
        'email_customer_receipt' => ['label' => 'Customer: payment receipt', 'type' => 'checkbox', 'col' => 12],
        'notify_whatsapp' => ['label' => 'WhatsApp alerts for new leads', 'type' => 'checkbox', 'col' => 12, 'help' => 'Needs a WhatsApp Business API provider (future integration).'],
    ]],
];
if (isset($_GET['tab']) && $_GET['tab'] === 'notify') {
    redirect(url('admin/settings?tab=email'));      // old link
}

$tab = array_key_exists($_GET['tab'] ?? '', $groups) ? $_GET['tab'] : 'business';
$group = $groups[$tab];
$current = [];
foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) $current[$r['setting_key']] = $r['setting_value'];
$values = [];
foreach ($group['fields'] as $k => $f) {
    $values[$k] = $current[$k] ?? '';
    if ($k === 'booking_time_slots') $values[$k] = str_replace('|', "\n", $values[$k]);
}
$errors = [];

// ---------------------------------------------------------------- email tab actions: test / send queue / retry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'email' && in_array($_POST['op'] ?? '', ['test', 'flush', 'retry'], true)) {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect(url('admin/settings?tab=email'));
    }
    $op = $_POST['op'];
    if ($op === 'test') {
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Enter a valid email address to send the test to.');
        } else {
            [$html, $text] = mail_template('Test email ✔', 'If you can read this, email sending from your website works. New leads, bookings and receipts will be emailed as set up in Settings.', [
                'Sent from' => url(), 'Mail server' => setting('smtp_host') . ':' . setting('smtp_port') . ' (' . setting('smtp_encryption') . ')', 'Time' => date('d M Y, h:i:s A'),
            ]);
            try {
                mail_send_now($to, 'Test email from ' . setting('business_name'), $html, $text);
                db_insert('email_queue', ['to_email' => $to, 'subject' => 'Test email', 'html_body' => '', 'text_body' => '', 'event' => 'test', 'status' => 'sent', 'attempts' => 1, 'sent_at' => date('Y-m-d H:i:s')]);
                audit_log((int) $me['id'], 'email_test', 'settings', null, "Test email sent to $to");
                flash_set('success', "Test email sent to $to. Check the inbox (and spam folder).");
            } catch (Throwable $e) {
                flash_set('error', 'Test email failed: ' . $e->getMessage());
            }
        }
    } elseif ($op === 'flush') {
        if (!mail_ready()) {
            flash_set('error', 'Email is switched off or not set up yet.');
        } else {
            $r = mail_process_queue(25);
            flash_set($r['failed'] ? 'error' : 'success', "Sent {$r['sent']} email(s)" . ($r['failed'] ? ", {$r['failed']} failed — see the error in the list below." : '.'));
        }
    } else {
        $n = db_query("UPDATE email_queue SET status = 'pending', attempts = 0, send_after = NOW(), last_error = NULL WHERE status = 'failed'")->rowCount();
        audit_log((int) $me['id'], 'email_retry', 'settings', null, "Re-queued $n failed email(s)");
        flash_set('success', "$n failed email(s) queued again.");
    }
    redirect(url('admin/settings?tab=email'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_'] = 'Your session expired. Please try again.';
    } else {
        [$data, $errors] = crud_validate(['table' => 'settings', 'fields' => $group['fields']], $_POST, null);
        if (array_key_exists('smtp_password', $data)) {
            // Blank = keep the saved password; otherwise store it encrypted (never in plain text)
            $raw = (string) ($_POST['smtp_password'] ?? '');
            if ($raw === '') unset($data['smtp_password']);
            else $data['smtp_password'] = secret_encrypt($raw);
        }
        if (isset($data['smtp_host'])) {
            $data['smtp_host'] = strtolower(preg_replace('#^[a-z]+://#i', '', trim((string) $data['smtp_host'])));
            if ($data['smtp_host'] !== '' && !preg_match('/^[a-z0-9.-]+$/', $data['smtp_host'])) $errors['smtp_host'] = 'Enter only the server name, e.g. smtp.hostinger.com';
        }
        if (!empty($data['mail_enabled']) && (empty($data['smtp_host']) || empty($data['mail_from_email']))) {
            $errors['mail_enabled'] = 'Fill in the SMTP host and “From” email before switching emails on.';
        }
        if (array_key_exists('notify_email_to', $data) && $data['notify_email_to'] !== null) {
            $list = array_filter(array_map('trim', explode(',', (string) $data['notify_email_to'])));
            foreach ($list as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) { $errors['notify_email_to'] = "“{$addr}” is not a valid email."; break; }
            }
            $data['notify_email_to'] = implode(', ', $list);
        }
        if (!empty($data['notify_email']) && empty($data['notify_email_to'])) $errors['notify_email_to'] = 'Add at least one team email address.';
        if (isset($data['whatsapp'])) {
            $data['whatsapp'] = preg_replace('/\D+/', '', (string) $data['whatsapp']);
            if (!preg_match('/^\d{11,13}$/', $data['whatsapp'])) $errors['whatsapp'] = 'Use digits with country code, e.g. 918502837831.';
        }
        if (!empty($data['google_maps_embed']) && !str_starts_with($data['google_maps_embed'], 'https://www.google.com/maps/embed')) {
            $errors['google_maps_embed'] = 'This must be a Google Maps embed link (https://www.google.com/maps/embed…).';
        }
        if (isset($data['booking_time_slots'])) {
            $slots = array_values(array_filter(array_map(fn($l) => clean_str($l, 40), preg_split('/\R/', (string) $data['booking_time_slots']))));
            if (!$slots) $errors['booking_time_slots'] = 'Add at least one time slot.';
            $data['booking_time_slots'] = implode('|', $slots);
        }
        $uploads = [];
        if (!$errors) {
            foreach ($group['fields'] as $k => $f) {
                if (($f['type'] ?? '') !== 'image') continue;
                [$file, $err] = upload_image($_FILES[$k] ?? null, $f['folder'], $f['max_side']);
                if ($err) { $errors[$k] = $err; continue; }
                if ($file) { $data[$k] = $file; $uploads[] = $file; }
                elseif (!empty($_POST[$k . '_remove'])) { $data[$k] = null; }
            }
        }
        if ($errors) {
            foreach ($uploads as $u) upload_delete($u);
            $values = array_merge($values, array_map(fn($v) => $v ?? '', $data));
        } else {
            $changed = [];
            foreach ($data as $k => $v) {
                $v = $v === null ? null : (string) $v;
                if ((string) ($current[$k] ?? '') === (string) ($v ?? '')) continue;
                db_query('INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v, $tab]);
                if (($group['fields'][$k]['type'] ?? '') === 'image' && !empty($current[$k])) upload_delete($current[$k]);
                $changed[$k] = [$current[$k] ?? null, $v];
            }
            if ($changed) {
                if (isset($changed['smtp_password'])) $changed['smtp_password'] = ['••••', '•••• (changed)'];   // never log secrets
                audit_log((int) $me['id'], 'updated', 'settings', null, 'Updated settings: ' . implode(', ', array_keys($changed)),
                    array_map(fn($c) => $c[0], $changed), array_map(fn($c) => $c[1], $changed));
            }
            flash_set('success', $changed ? 'Settings saved. The website shows the changes immediately.' : 'Nothing changed.');
            redirect(url('admin/settings?tab=' . $tab));
        }
    }
}

$admin = ['title' => 'Settings', 'subtitle' => 'Business details shown across the website and CRM', 'active' => 'settings'];
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="row g-4">
  <div class="col-lg-3">
    <nav class="panel settings-nav" aria-label="Settings sections">
      <?php foreach ($groups as $k => $g): ?>
        <a class="<?= $k === $tab ? 'is-active' : '' ?>" href="<?= e(url('admin/settings?tab=' . $k)) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><i class="bi <?= e($g['icon']) ?>"></i> <?= e($g['title']) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="col-lg-9">
    <?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php elseif ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="panel" novalidate>
      <?= csrf_field() ?>
      <div class="panel-head"><h2><i class="bi <?= e($group['icon']) ?> me-1"></i> <?= e($group['title']) ?></h2></div>
      <div class="panel-body row g-3">
        <?php if ($tab === 'email'): ?>
          <div class="col-12">
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <span class="small text-muted-2">Quick fill:</span>
              <button class="chip-link" type="button" data-smtp-preset="smtp.hostinger.com|465|ssl">Hostinger email</button>
              <button class="chip-link" type="button" data-smtp-preset="smtp.gmail.com|587|tls">Gmail / Google Workspace</button>
              <button class="chip-link" type="button" data-smtp-preset="smtp.zoho.in|465|ssl">Zoho Mail (India)</button>
              <button class="chip-link" type="button" data-smtp-preset="smtp.office365.com|587|tls">Outlook / Microsoft 365</button>
            </div>
            <div class="form-text">Gmail needs an <strong>App Password</strong> (Google Account → Security → 2-Step Verification → App passwords), not your normal password.</div>
          </div>
        <?php endif; ?>
        <?php foreach ($group['fields'] as $k => $f): ?>
          <?php if (!empty($f['section'])): ?><div class="col-12"><div class="settings-section"><?= e($f['section']) ?></div></div><?php endif; ?>
          <div class="col-md-<?= (int) ($f['col'] ?? 12) ?>"><?= crud_field_html($k, $f, $values[$k], $errors[$k] ?? null) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="panel-foot"><span class="small text-muted-2">Changes are recorded in the audit log.</span><button class="btn btn-grad" type="submit">Save <?= e(strtolower($group['title'])) ?></button></div>
    </form>

    <?php if ($tab === 'email'):
        $ready = mail_ready();
        $counts = array_column(db_all("SELECT status, COUNT(*) n FROM email_queue GROUP BY status"), 'n', 'status');
        $recent = db_all('SELECT id, to_email, subject, event, related, status, attempts, last_error, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT 15');
        $eventLabels = ['lead.new' => 'New lead alert', 'lead.confirmation' => 'Request received', 'booking.confirmation' => 'Booking confirmed', 'payment.receipt' => 'Payment receipt', 'test' => 'Test'];
    ?>
      <section class="panel mt-4">
        <div class="panel-head"><h2><i class="bi bi-send me-1"></i> Send a test email</h2>
          <?= status_badge($ready ? 'Email is ON' : (setting('mail_enabled') === '1' ? 'Incomplete setup' : 'Email is OFF'), $ready ? 'success' : 'secondary') ?></div>
        <form method="post" class="panel-body d-flex flex-wrap gap-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="op" value="test">
          <div class="flex-grow-1" style="max-width:360px"><label class="form-label" for="testTo">Send to</label>
            <input class="form-control" id="testTo" name="test_to" type="email" required value="<?= e(auth_user()['email'] !== 'admin@mach.local' ? auth_user()['email'] : '') ?>" placeholder="you@example.com"></div>
          <button class="btn btn-grad" type="submit"><i class="bi bi-send"></i> Send test email</button>
          <div class="form-text w-100 mb-0">Uses the <strong>saved</strong> settings above — save first. The test is sent immediately and shows the exact error if something is wrong.</div>
        </form>
      </section>

      <section class="panel mt-4">
        <div class="panel-head">
          <h2><i class="bi bi-envelope-paper me-1"></i> Recent emails</h2>
          <div class="d-flex gap-2 align-items-center small text-muted-2">
            Sent <?= (int) ($counts['sent'] ?? 0) ?> · Waiting <?= (int) ($counts['pending'] ?? 0) + (int) ($counts['sending'] ?? 0) ?> · Failed <?= (int) ($counts['failed'] ?? 0) ?>
            <?php if (!empty($counts['pending'])): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="op" value="flush"><button class="btn btn-ghost btn-sm" type="submit">Send waiting now</button></form><?php endif; ?>
            <?php if (!empty($counts['failed'])): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="op" value="retry"><button class="btn btn-ghost btn-sm" type="submit">Retry failed</button></form><?php endif; ?>
          </div>
        </div>
        <?php if ($recent): ?>
          <ul class="list-rows">
            <?php foreach ($recent as $m): ?>
              <li class="align-items-start">
                <span class="row-icon<?= $m['status'] === 'failed' ? ' is-late' : '' ?>"><i class="bi <?= $m['status'] === 'sent' ? 'bi-check2' : ($m['status'] === 'failed' ? 'bi-x-lg' : 'bi-hourglass-split') ?>"></i></span>
                <div class="flex-grow-1 min-w-0">
                  <strong class="d-block text-truncate"><?= e($m['subject']) ?></strong>
                  <small class="text-muted-2"><?= e($m['to_email']) ?> · <?= e($eventLabels[$m['event']] ?? $m['event']) ?><?= $m['related'] ? ' · ' . e($m['related']) : '' ?> · <?= e(time_ago($m['sent_at'] ?? $m['created_at'])) ?></small>
                  <?php if ($m['last_error']): ?><small class="d-block text-danger text-break"><?= e($m['last_error']) ?><?= $m['status'] === 'pending' ? ' — will retry automatically' : '' ?></small><?php endif; ?>
                </div>
                <?= status_badge(ucfirst($m['status']), ['sent' => 'success', 'failed' => 'danger', 'pending' => 'warning', 'sending' => 'info'][$m['status']]) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-mini">No emails yet. Once email is on, new leads, bookings and receipts will appear here.</div>
        <?php endif; ?>
        <div class="panel-foot small text-muted-2">Emails go out right after each action. On the live site also add the cron job <span class="mono">cron/send-emails.php</span> (every 5 min) as a safety net.</div>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
