<?php
// Sends "follow-up overdue" notifications. Schedule every 10 minutes:
//   Linux:   */10 * * * * php /path/to/site/cron/notify-overdue.php
//   Windows: Task Scheduler -> php.exe C:\xampp\htdocs\mach\cron\notify-overdue.php
// The admin panel also runs this check on page load, so cron is optional.
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/includes/bootstrap.php';
echo 'Overdue follow-ups notified: ' . followups_notify_overdue() . "\n";
echo 'Overdue complaints notified: ' . complaints_notify_overdue() . "\n";
