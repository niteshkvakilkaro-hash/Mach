<?php
/** Technician job detail: customer contact, address, notes and the next-step button. */
require dirname(__DIR__) . '/includes/bootstrap.php';
[$me, $tech] = tech_require();

$b = tech_job(clean_str($_GET['number'] ?? '', 20), (int) $tech['id']);
if (!$b) {
    http_response_code(404);
    $appTitle = 'Job not found';
    $backUrl = url('technician');
    require ROOT_PATH . '/includes/technician/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2 class="h5">Job not found</h2><p>It may have been reassigned to someone else.</p></div>';
    require ROOT_PATH . '/includes/technician/footer.php';
    exit;
}

$statuses = booking_statuses();
$next = TECH_NEXT_STEP[$b['status']] ?? null;
$allowed = tech_allowed_statuses();
$closed = (int) $b['is_closed'];
$history = array_slice(booking_history((int) $b['id']), 0, 8);
$amountValue = fn(?string $v) => $v !== null ? rtrim(rtrim($v, '0'), '.') : '';
$payable = (float) ($b['final_amount'] ?? $b['quoted_amount'] ?? $b['estimated_amount'] ?? 0) - (float) $b['discount_amount'];

$appTitle = $b['booking_number'];
$backUrl = url('technician');
$appTab = '';
require ROOT_PATH . '/includes/technician/header.php';
?>
<section class="job-hero">
  <div class="d-flex justify-content-between align-items-start gap-2">
    <div>
      <h1><?= e($b['service_name'] ?? 'Service') ?></h1>
      <p><?= e(date('D, d M', strtotime($b['scheduled_date']))) ?> · <?= e($b['scheduled_time']) ?></p>
    </div>
    <?= status_badge($b['status_label'], $b['status_color']) ?>
  </div>
</section>

<!-- Customer -->
<section class="panel mb-3">
  <div class="panel-body">
    <div class="d-flex align-items-center gap-3 mb-3">
      <span class="avatar"><?= e(initials($b['customer_name'])) ?></span>
      <div class="min-w-0"><strong class="d-block"><?= e($b['customer_name']) ?></strong><small class="text-muted-2"><?= e($b['phone']) ?><?= $b['alt_phone'] ? ' · ' . e($b['alt_phone']) : '' ?></small></div>
    </div>
    <p class="mb-3"><i class="bi bi-geo-alt text-grad-2"></i> <?= e($b['address']) ?><?= $b['area_name'] ? ', ' . e($b['area_name']) : '' ?><?= $b['city'] ? ', ' . e($b['city']) : '' ?><?= $b['pincode'] ? ' – ' . e($b['pincode']) : '' ?></p>
    <div class="big-actions">
      <a class="btn btn-ghost" href="<?= e(tel_link('+91' . $b['phone'])) ?>"><i class="bi bi-telephone-fill"></i> Call</a>
      <a class="btn btn-wa" href="<?= e(whatsapp_link(tech_whatsapp_text($b, $tech), '91' . $b['phone'])) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>
      <a class="btn btn-ghost" href="<?= e(maps_link($b['address'], $b['area_name'], $b['city'])) ?>" target="_blank" rel="noopener"><i class="bi bi-map"></i> Map</a>
    </div>
  </div>
</section>

<?php if ($b['notes']): ?>
  <section class="panel mb-3"><div class="panel-body"><div class="quote-box"><small>Job notes from office</small><?= nl2br(e($b['notes'])) ?></div></div></section>
<?php endif; ?>

<!-- Next step -->
<?php if (!$closed): ?>
<section class="panel next-step mb-3" id="update">
  <form class="panel-body" data-ajax action="<?= e(url('api/technician/status.php')) ?>">
    <input type="hidden" name="number" value="<?= e($b['booking_number']) ?>">
    <?php if ($next): [$nextStatus, $nextLabel, $nextIcon, $amountField] = $next; ?>
      <input type="hidden" name="status" value="<?= e($nextStatus) ?>" data-next-status>
      <?php if ($amountField): ?>
        <label class="form-label" for="amt"><?= $amountField === 'quoted_amount' ? 'Quotation amount (₹)' : 'Final amount to collect (₹)' ?></label>
        <input class="form-control form-control-lg mb-2" id="amt" name="<?= $amountField ?>" inputmode="decimal" required
               value="<?= e($amountValue($b[$amountField] ?? ($amountField === 'final_amount' ? ($b['quoted_amount'] ?? $b['estimated_amount']) : $b['estimated_amount']))) ?>">
      <?php endif; ?>
      <label class="form-label" for="note">Note <small class="text-muted-2 fw-normal">(optional)</small></label>
      <textarea class="form-control mb-3" id="note" name="note" rows="2" maxlength="500" placeholder="<?= $nextStatus === 'quotation_sent' ? 'What needs to be replaced?' : 'Anything the office should know' ?>"></textarea>
      <div class="form-alert" data-form-alert></div>
      <button class="btn btn-grad btn-lg w-100 next-btn" type="submit"><i class="bi <?= e($nextIcon) ?>"></i> <?= e($nextLabel) ?></button>
    <?php else: ?>
      <p class="text-muted-2 mb-2">Choose the current stage of this job.</p>
    <?php endif; ?>

    <details class="mt-3"<?= $next ? '' : ' open' ?>>
      <summary class="small text-muted-2">Set a different stage</summary>
      <div class="d-flex gap-2 mt-2">
        <select class="form-select" aria-label="Stage" data-other-status>
          <option value="">Choose…</option>
          <?php foreach ($allowed as $slug => $s): if ($slug === $b['status']) continue; ?><option value="<?= e($slug) ?>"><?= e($s['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <p class="form-text mb-0">To cancel or reschedule, call the office.</p>
    </details>
  </form>
</section>
<?php else: ?>
  <div class="alert alert-<?= $b['status'] === 'completed' ? 'success' : 'secondary' ?>"><i class="bi bi-<?= $b['status'] === 'completed' ? 'check2-circle' : 'x-circle' ?>"></i> This job is <?= e(strtolower($b['status_label'])) ?>.</div>
<?php endif; ?>

<!-- Amounts & payment -->
<?php
$balance = booking_balance($b);
$jobPayments = booking_payments((int) $b['id']);
$canCollect = $b['status'] !== 'cancelled' && $balance !== null && $balance > 0
    && in_array($b['status'], ['in_progress', 'payment_pending', 'completed'], true);
?>
<section class="panel mb-3" id="payment">
  <div class="amount-grid">
    <div><span>Bill</span><strong><?= e(format_inr(booking_amount_due($b)) ?: '—') ?></strong></div>
    <div><span>Paid</span><strong><?= e(format_inr($b['amount_paid'])) ?></strong></div>
    <div><span>To collect</span><strong class="<?= $balance > 0 ? 'text-warning' : 'text-success' ?>"><?= e($balance === null ? '—' : format_inr($balance)) ?></strong></div>
  </div>
  <?php if ($canCollect): ?>
    <form class="panel-body border-top-line" data-ajax action="<?= e(url('api/technician/payment.php')) ?>">
      <input type="hidden" name="number" value="<?= e($b['booking_number']) ?>">
      <label class="form-label" for="payAmt">Collect payment (₹)</label>
      <input class="form-control form-control-lg mb-2" id="payAmt" name="amount" inputmode="decimal" required value="<?= e(rtrim(rtrim(number_format($balance, 2, '.', ''), '0'), '.')) ?>">
      <div class="pay-methods mb-2" role="radiogroup" aria-label="Payment method">
        <?php foreach (['cash' => 'bi-cash', 'upi' => 'bi-qr-code', 'card' => 'bi-credit-card'] as $m => $icon): ?>
          <label class="pay-method"><input type="radio" name="payment_method" value="<?= $m ?>"<?= $m === 'cash' ? ' checked' : '' ?> data-pay-method><i class="bi <?= $icon ?>"></i><?= e(PAYMENT_METHODS[$m]) ?></label>
        <?php endforeach; ?>
      </div>
      <input class="form-control mb-2 d-none" name="transaction_id" maxlength="100" placeholder="UPI / card reference no." aria-label="Transaction reference" data-pay-ref>
      <div class="form-alert" data-form-alert></div>
      <button class="btn btn-wa btn-lg w-100" type="submit"><i class="bi bi-check2-circle"></i> Payment received</button>
    </form>
  <?php endif; ?>
  <?php if ($jobPayments): ?>
    <ul class="list-rows border-top-line">
      <?php foreach ($jobPayments as $p): $p += ['booking_number' => $b['booking_number'], 'service_name' => $b['service_name']]; ?>
        <li><span class="row-icon"><i class="bi bi-currency-rupee"></i></span>
          <div class="flex-grow-1 min-w-0"><strong><?= e(format_inr($p['amount'])) ?></strong> · <?= e(PAYMENT_METHODS[$p['payment_method']]) ?><small class="d-block text-muted-2"><?= e(admin_datetime($p['payment_date'])) ?></small></div>
          <?php if ($p['status'] === 'paid'): ?><a class="icon-btn icon-btn-sm icon-wa" href="<?= e(whatsapp_link(payment_receipt_text($p), '91' . $b['phone'])) ?>" target="_blank" rel="noopener" aria-label="Send receipt on WhatsApp" title="Send receipt"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
          <?= status_badge(PAYMENT_STATUSES[$p['status']], PAYMENT_STATUS_COLORS[$p['status']]) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<!-- History -->
<section class="panel mb-3">
  <div class="panel-head"><h2>Updates</h2></div>
  <?php if (!$history): ?><div class="empty-mini">No updates yet.</div><?php endif; ?>
  <ul class="timeline">
    <?php foreach ($history as $h): $st = $statuses[$h['new_status']] ?? ['label' => $h['new_status'], 'color' => 'secondary']; ?>
      <li><span class="tl-dot sb-<?= e($st['color']) ?>"></span>
        <div><strong><?= e($st['label']) ?></strong><?php if ($h['note']): ?><small class="d-block text-muted-2"><?= e($h['note']) ?></small><?php endif; ?>
          <small class="d-block text-muted-2"><?= e($h['user_name'] ?? 'System') ?> · <?= e(admin_datetime($h['created_at'])) ?></small></div></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php require ROOT_PATH . '/includes/technician/footer.php'; ?>
