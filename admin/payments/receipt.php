<?php
/** Printable payment receipt (Print → Save as PDF), with a WhatsApp share button. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('payments.view');

$p = payment_find(clean_str($_GET['number'] ?? '', 20));
if (!$p) {
    http_response_code(404);
    exit('Receipt not found.');
}
$due = booking_amount_due($p);
$balance = $due === null ? null : max(0, $due - (float) $p['amount_paid']);
$businessName = setting('business_name');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($p['payment_number']) ?> · <?= e($businessName) ?></title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root { --ink: #1b1530; --muted: #6b6485; --line: #e7e3f3; --brand: #7c5cf0; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f4f2fa; color: var(--ink); font-family: "Plus Jakarta Sans", system-ui, sans-serif; }
  .sheet { max-width: 720px; margin: 32px auto; background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 20px 60px -30px rgba(40, 20, 90, .35); }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid var(--ink); padding-bottom: 20px; }
  .brand { font-size: 24px; font-weight: 800; }
  .brand small { display: block; font-size: 12px; font-weight: 400; color: var(--muted); margin-top: 4px; line-height: 1.5; }
  .title { text-align: right; }
  .title h1 { margin: 0; font-size: 20px; letter-spacing: .08em; text-transform: uppercase; }
  .title div { color: var(--muted); font-size: 13px; margin-top: 4px; }
  .status { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1.5px solid currentColor; }
  .paid { color: #0a7d2c; } .pending { color: #9a6400; } .failed, .refunded { color: #b42323; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 24px 0; }
  .label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  td { padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
  td:last-child { text-align: right; font-weight: 600; }
  .total td { border-bottom: 0; font-size: 18px; font-weight: 800; padding-top: 16px; }
  .foot { margin-top: 28px; color: var(--muted); font-size: 12px; line-height: 1.6; }
  .actions { max-width: 720px; margin: 0 auto 32px; display: flex; gap: 8px; justify-content: flex-end; padding: 0 16px; }
  .actions a, .actions button { font: inherit; font-weight: 600; font-size: 14px; border: 0; border-radius: 10px; padding: 10px 16px; cursor: pointer; text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
  .btn-print { background: var(--ink); color: #fff; }
  .btn-wa { background: #25d366; color: #06270f; }
  @media (max-width: 560px) { .sheet { margin: 0; border-radius: 0; padding: 24px; } .grid { grid-template-columns: 1fr; } .top { flex-direction: column; } .title { text-align: left; } }
  @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; } .actions { display: none; } }
</style>
</head>
<body>
<main class="sheet">
  <div class="top">
    <div class="brand"><?= e($businessName) ?>
      <small><?= e(setting('address')) ?><br><?= e(setting('phone')) ?> · <?= e(setting('email')) ?></small>
    </div>
    <div class="title">
      <h1>Payment receipt</h1>
      <div><?= e($p['payment_number']) ?></div>
      <div><?= e(date('d M Y, h:i A', strtotime($p['payment_date'] ?? $p['created_at']))) ?></div>
      <span class="status <?= e($p['status']) ?>"><?= e(PAYMENT_STATUSES[$p['status']]) ?></span>
    </div>
  </div>

  <div class="grid">
    <div><div class="label">Received from</div><strong><?= e($p['customer_name']) ?></strong><br><?= e($p['phone']) ?><br><span style="color:var(--muted);font-size:13px"><?= e($p['address']) ?></span></div>
    <div><div class="label">For</div><strong><?= e($p['service_name'] ?? 'Service') ?></strong><br>Booking <?= e($p['booking_number']) ?><br>Visit on <?= e(date('d M Y', strtotime($p['scheduled_date']))) ?>
      <?php if ($p['warranty_until']): ?><br><span style="color:var(--muted);font-size:13px">Warranty till <?= e(date('d M Y', strtotime($p['warranty_until']))) ?></span><?php endif; ?></div>
  </div>

  <table>
    <tr><td>Payment method</td><td><?= e(PAYMENT_METHODS[$p['payment_method']]) ?></td></tr>
    <?php if ($p['transaction_id']): ?><tr><td>Reference</td><td><?= e($p['transaction_id']) ?></td></tr><?php endif; ?>
    <?php if ($due !== null): ?>
      <tr><td>Bill amount<?= (float) $p['discount_amount'] > 0 ? ' (after ' . e(format_inr($p['discount_amount'])) . ' discount)' : '' ?></td><td><?= e(format_inr($due, true)) ?></td></tr>
      <tr><td>Total paid so far</td><td><?= e(format_inr($p['amount_paid'], true)) ?></td></tr>
      <tr><td>Balance</td><td><?= e(format_inr($balance, true)) ?></td></tr>
    <?php endif; ?>
    <tr class="total"><td>Amount received</td><td><?= e(format_inr($p['amount'], true)) ?></td></tr>
  </table>

  <p class="foot">
    Collected by <?= e($p['collected_by_technician_name'] ? $p['collected_by_technician_name'] . ' (technician)' : ($p['collected_by_user_name'] ?? $businessName)) ?>.<br>
    This is a computer-generated receipt and does not need a signature. Thank you for choosing <?= e($businessName) ?>.
  </p>
</main>
<div class="actions">
  <a class="btn-wa" href="<?= e(whatsapp_link(payment_receipt_text($p), '91' . $p['phone'])) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Send on WhatsApp</a>
  <button class="btn-print" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
</div>
</body>
</html>
