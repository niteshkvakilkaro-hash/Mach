<?php
// Sends queued emails. Schedule every 5 minutes (Hostinger → Cron Jobs):
//   /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron/send-emails.php
// Emails are also sent right after each website/admin request, so cron is a safety net.
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/includes/bootstrap.php';
$r = mail_process_queue(50);
echo "Emails sent: {$r['sent']}, failed: {$r['failed']}\n";
