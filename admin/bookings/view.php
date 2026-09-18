<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('bookings.view');

$b = booking_find(clean_str($_GET['number'] ?? '', 20));
if (!$b) {
    http_response_code(404);
    $admin = ['title' => 'Booking not found', 'active' => 'bookings'];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2>Booking not found</h2><a class="btn btn-grad" href="' . e(url('admin/bookings')) . '">Back to bookings</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}

$statuses = booking_statuses();
$history = booking_history((int) $b['id']);
$payments = can('payments.view') ? booking_payments((int) $b['id']) : [];
$canEdit = can('bookings.edit');
$num = e($b['booking_number']);
$flowIndex = array_search($b['status'], BOOKING_FLOW, true);
$reached = [];
foreach ($history as $h) $reached[$h['new_status']] = true;
$slots = time_slots();
if (!in_array($b['scheduled_time'], $slots, true)) $slots[] = $b['scheduled_time'];
$due = max(0, (float) ($b['final_amount'] ?? $b['quoted_amount'] ?? $b['estimated_amount'] ?? 0) - (float) $b['discount_amount'] - (float) $b['amount_paid']);

$actions = '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $b['phone'])) . '"><i class="bi bi-telephone"></i> Call customer</a>'
    . '<a class="btn btn-wa btn-sm" href="' . e(whatsapp_link(booking_whatsapp_text($b), '91' . $b['phone'])) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
if ($b['technician_phone']) {
    $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $b['technician_phone'])) . '"><i class="bi bi-person-gear"></i> Call technician</a>';
}
$admin = ['title' => ($b['service_name'] ?? 'Booking') . ' · ' . $b['customer_name'], 'active' => 'bookings', 'actions' => $actions];
$field = function (string $label, $value, bool $raw = false) {
    echo '<div class="kv"><dt>' . e($label) . '</dt><dd>' . ($value !== null && $value !== '' ? ($raw ? $value : e($value)) : '<span class="text-muted-2">—</span>') . '</dd></div>';
};
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="lead-meta mb-3">
  <a class="text-muted-2 small" href="<?= e(url('admin/bookings')) ?>"><i class="bi bi-arrow-left"></i> Bookings</a>
  <span class="mono fw-semibold"><?= $num ?></span>
  <?= status_badge($b['status_label'], $b['status_color']) ?>
  <?php if ($b['reschedule_count']): ?><span class="tag"><i class="bi bi-arrow-clockwise"></i> Rescheduled ×<?= (int) $b['reschedule_count'] ?></span><?php endif; ?>
  <?php if ($b['lead_number']): ?><a class="tag" href="<?= e(lead_url($b['lead_number'])) ?>">Lead <?= e($b['lead_number']) ?></a><?php endif; ?>
  <a class="tag" href="<?= e(url('admin/customers/view?number=' . $b['customer_number'])) ?>">Customer <?= e($b['customer_number']) ?></a>
  <span class="text-muted-2 small ms-auto">Created <?= e(admin_datetime($b['created_at'])) ?><?= $b['created_by_name'] ? ' by ' . e($b['created_by_name']) : '' ?></span>
</div>

<?php if ($b['status'] === 'cancelled'): ?>
  <div class="alert alert-secondary small"><i class="bi bi-x-circle"></i> Cancelled<?= $b['cancel_reason'] ? ': ' . e($b['cancel_reason']) : '' ?></div>
<?php else: ?>
  <ol class="stage-track stage-track-11 panel mb-4" aria-label="Job progress">
    <?php foreach (BOOKING_FLOW as $i => $slug): $done = isset($reached[$slug]) || ($flowIndex !== false && $i <= $flowIndex); ?>
      <li class="<?= $done ? 'is-done' : '' ?><?= $slug === $b['status'] ? ' is-current' : '' ?>"<?= $slug === $b['status'] ? ' aria-current="step"' : '' ?>>
        <span class="st-dot"><?= $done ? '<i class="bi bi-check-lg"></i>' : '' ?></span><span class="st-label"><?= e($statuses[$slug]['label']) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <section class="panel mb-4">
      <div class="panel-head"><h2>Job details</h2></div>
      <div class="panel-body row g-4">
        <dl class="col-md-6 kv-list mb-0">
          <?php $field('Scheduled', date('D, d M Y', strtotime($b['scheduled_date'])) . ' · ' . $b['scheduled_time']); ?>
          <?php $field('Service', $b['service_name']); ?>
          <?php $field('Technician', $b['technician_name'] ? $b['technician_name'] . ' · ' . $b['technician_phone'] : null); ?>
          <?php $field('Started', $b['started_at'] ? admin_datetime($b['started_at']) : null); ?>
          <?php $field('Completed', $b['completed_at'] ? admin_datetime($b['completed_at']) : null); ?>
          <?php $field('Warranty until', $b['warranty_until'] ? date('d M Y', strtotime($b['warranty_until'])) : null); ?>
        </dl>
        <dl class="col-md-6 kv-list mb-0">
          <?php $field('Customer', $b['customer_name']); ?>
          <?php $field('Phone', '<a href="' . e(tel_link('+91' . $b['phone'])) . '">' . e($b['phone']) . '</a>' . ($b['alt_phone'] ? ' · ' . e($b['alt_phone']) : ''), true); ?>
          <?php $field('Address', $b['address']); ?>
          <?php $field('Area / City', trim(($b['area_name'] ? $b['area_name'] . ', ' : '') . ($b['city'] ?? ''), ', ')); ?>
          <?php $field('Pincode', $b['pincode']); ?>
          <?php $field('Map', '<a href="https://www.google.com/maps/search/?api=1&amp;query=' . e(rawurlencode(trim($b['address'] . ', ' . ($b['area_name'] ?? '') . ', ' . ($b['city'] ?? '')))) . '" target="_blank" rel="noopener">Open in Google Maps <i class="bi bi-box-arrow-up-right"></i></a>', true); ?>
        </dl>
        <?php if ($b['notes']): ?><div class="col-12"><div class="quote-box"><small>Notes</small><?= nl2br(e($b['notes'])) ?></div></div><?php endif; ?>
      </div>
    </section>

    <section class="panel mb-4">
      <div class="panel-head"><h2>Amounts</h2><?= status_badge(ucfirst($b['payment_status']), ['paid' => 'success', 'partial' => 'warning', 'refunded' => 'secondary'][$b['payment_status']] ?? 'danger') ?></div>
      <div class="amount-grid">
        <div><span>Estimated</span><strong><?= e(format_inr($b['estimated_amount']) ?: '—') ?></strong></div>
        <div><span>Quoted</span><strong><?= e(format_inr($b['quoted_amount']) ?: '—') ?></strong></div>
        <div><span>Discount</span><strong><?= e(format_inr($b['discount_amount'])) ?></strong></div>
        <div><span>Final</span><strong><?= e(format_inr($b['final_amount']) ?: '—') ?></strong></div>
        <div><span>Paid</span><strong><?= e(format_inr($b['amount_paid'])) ?></strong></div>
        <div><span>Balance</span><strong class="<?= $due > 0 ? 'text-warning' : '' ?>"><?= e(format_inr($due)) ?></strong></div>
      </div>
      <?php if ($payments): ?>
        <ul class="list-rows border-top-line">
          <?php foreach ($payments as $p): ?>
            <li><span class="row-icon"><i class="bi bi-currency-rupee"></i></span>
              <div class="flex-grow-1 min-w-0"><strong><?= e(format_inr($p['amount'], true)) ?></strong> · <?= e(PAYMENT_METHODS[$p['payment_method']]) ?>
                <small class="d-block text-muted-2"><span class="mono"><?= e($p['payment_number']) ?></span><?= $p['transaction_id'] ? ' · Ref ' . e($p['transaction_id']) : '' ?> · <?= e(admin_datetime($p['payment_date'])) ?></small></div>
              <?= status_badge(PAYMENT_STATUSES[$p['status']], PAYMENT_STATUS_COLORS[$p['status']]) ?>
              <div class="dropdown">
                <button class="icon-btn icon-btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Payment actions"><i class="bi bi-three-dots-vertical"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="<?= e(url('admin/payments/receipt?number=' . $p['payment_number'])) ?>" target="_blank"><i class="bi bi-receipt me-2"></i>Receipt</a></li>
                  <?php if (can('payments.edit')): ?>
                    <?php if ($p['status'] === 'pending'): ?>
                      <li><button class="dropdown-item" type="button" data-payment-status="paid" data-number="<?= e($p['payment_number']) ?>"><i class="bi bi-check2-circle me-2"></i>Mark as paid</button></li>
                      <li><button class="dropdown-item" type="button" data-payment-status="failed" data-number="<?= e($p['payment_number']) ?>"><i class="bi bi-x-circle me-2"></i>Mark as failed</button></li>
                    <?php elseif ($p['status'] === 'paid'): ?>
                      <li><button class="dropdown-item text-danger" type="button" data-payment-status="refunded" data-number="<?= e($p['payment_number']) ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Refund</button></li>
                    <?php endif; ?>
                  <?php endif; ?>
                </ul>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (can('payments.create') && $b['status'] !== 'cancelled' && ($due > 0 || booking_amount_due($b) === null)): ?>
        <details class="pay-form border-top-line" id="payment"<?= in_array($b['status'], ['payment_pending', 'completed'], true) ? ' open' : '' ?>>
          <summary><i class="bi bi-plus-circle"></i> Record payment</summary>
          <form class="row g-2" data-ajax action="<?= e(url('api/payments/create.php')) ?>">
            <input type="hidden" name="number" value="<?= $num ?>">
            <div class="col-sm-4"><label class="form-label" for="pyAmt">Amount (₹)</label><input class="form-control" id="pyAmt" name="amount" inputmode="decimal" required value="<?= $due > 0 ? e(rtrim(rtrim(number_format($due, 2, '.', ''), '0'), '.')) : '' ?>"></div>
            <div class="col-sm-4"><label class="form-label" for="pyMethod">Method</label><select class="form-select" id="pyMethod" name="payment_method" required><?= select_options(PAYMENT_METHODS, 'cash') ?></select></div>
            <div class="col-sm-4"><label class="form-label" for="pyTxn">Reference / UPI ID</label><input class="form-control" id="pyTxn" name="transaction_id" maxlength="100" placeholder="Optional for cash"></div>
            <div class="col-sm-4"><label class="form-label" for="pyDate">Received on</label><input class="form-control" id="pyDate" name="payment_date" type="datetime-local" value="<?= date('Y-m-d\TH:i') ?>" max="<?= date('Y-m-d\TH:i', strtotime('+5 minutes')) ?>"></div>
            <div class="col-sm-4"><label class="form-label" for="pyBy">Collected by</label>
              <select class="form-select" id="pyBy" name="collected_by"><option value="office">Office / me</option><?php if ($b['technician_id']): ?><option value="technician"<?= $b['status'] === 'completed' ? ' selected' : '' ?>><?= e($b['technician_name']) ?> (technician)</option><?php endif; ?></select></div>
            <div class="col-sm-4"><label class="form-label" for="pySt">Status</label><select class="form-select" id="pySt" name="status"><option value="paid">Received</option><option value="pending">Pending (e.g. cheque)</option></select></div>
            <div class="col-12"><input class="form-control" name="notes" maxlength="500" placeholder="Note (optional)" aria-label="Note"></div>
            <div class="col-12"><div class="form-alert" data-form-alert></div><button class="btn btn-grad" type="submit"><i class="bi bi-check2"></i> Save payment</button></div>
          </form>
        </details>
      <?php elseif ($due <= 0 && $b['amount_paid'] > 0): ?>
        <div class="panel-foot small text-success"><i class="bi bi-check2-circle"></i> Fully paid.</div>
      <?php endif; ?>
    </section>

    <?php
    $fb = db_one('SELECT * FROM feedback WHERE booking_id = ?', [$b['id']]);
    $jobComplaints = db_all('SELECT complaint_number, category, status, created_at FROM complaints WHERE booking_id = ? OR revisit_booking_id = ? ORDER BY id DESC', [$b['id'], $b['id']]);
    ?>
    <?php if ($b['status'] === 'completed' || $fb || $jobComplaints): ?>
    <section class="panel mb-4" id="quality">
      <div class="panel-head"><h2>Customer feedback</h2>
        <?php if (can('complaints.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/complaints/new?booking=' . $b['booking_number'])) ?>"><i class="bi bi-exclamation-diamond"></i> Log complaint</a><?php endif; ?></div>
      <div class="panel-body">
        <?php if ($fb && $fb['status'] === 'submitted'): ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <span class="fb-score<?= (int) $fb['rating'] <= (int) setting('feedback_low_rating', '3') ? ' is-low' : '' ?>"><?= (int) $fb['rating'] ?>★</span>
            <div><strong>Rated <?= (int) $fb['rating'] ?> out of 5</strong><small class="d-block text-muted-2"><?= e(admin_datetime($fb['submitted_at'])) ?><?= $fb['google_clicked_at'] ? ' · clicked “Review on Google”' : '' ?></small></div>
          </div>
          <?php if ($fb['tags']): ?><div class="mb-2"><?php foreach (explode(',', $fb['tags']) as $tg): ?><span class="tag me-1<?= isset(FEEDBACK_TAGS_BAD[$tg]) ? ' tag-bad' : ' tag-good' ?>"><?= e((FEEDBACK_TAGS_GOOD + FEEDBACK_TAGS_BAD)[$tg] ?? $tg) ?></span><?php endforeach; ?></div><?php endif; ?>
          <?php if ($fb['comment']): ?><div class="quote-box">“<?= e($fb['comment']) ?>”</div><?php endif; ?>
        <?php elseif ($fb): ?>
          <p class="mb-2 text-muted-2"><i class="bi bi-hourglass-split"></i> Waiting for the customer’s rating (requested <?= e(time_ago($fb['requested_at'])) ?><?= $b['email'] ? ', emailed' : '' ?>).</p>
          <a class="btn btn-wa btn-sm" href="<?= e(whatsapp_link('Hi ' . strtok($b['customer_name'], ' ') . ', thank you for choosing ' . setting('business_name') . '! How was your ' . ($b['service_name'] ?: 'service') . '? Please rate us in 10 seconds: ' . feedback_url($fb['token']), '91' . $b['phone'])) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Ask for rating on WhatsApp</a>
          <button class="btn btn-ghost btn-sm" type="button" onclick="navigator.clipboard.writeText('<?= e(feedback_url($fb['token'])) ?>');adminToast('Rating link copied')"><i class="bi bi-link-45deg"></i> Copy link</button>
        <?php elseif ($b['status'] === 'completed'): ?>
          <p class="text-muted-2 mb-0">Feedback requests are switched off in Settings.</p>
        <?php endif; ?>
        <?php if ($jobComplaints): ?>
          <div class="panel-subhead mt-3 mx-n3">Complaints</div>
          <ul class="list-rows">
            <?php foreach ($jobComplaints as $jc): ?>
              <li><div class="flex-grow-1"><a class="mono" href="<?= e(url('admin/complaints/view?number=' . $jc['complaint_number'])) ?>"><?= e($jc['complaint_number']) ?></a><small class="d-block text-muted-2"><?= e(COMPLAINT_CATEGORIES[$jc['category']]) ?> · <?= e(time_ago($jc['created_at'])) ?></small></div>
                <?= status_badge(COMPLAINT_STATUSES[$jc['status']], COMPLAINT_STATUS_COLORS[$jc['status']]) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="panel mb-4">
      <div class="panel-head"><h2>History</h2></div>
      <ul class="timeline timeline-lg">
        <?php foreach ($history as $h): $st = $statuses[$h['new_status']] ?? ['label' => $h['new_status'], 'color' => 'secondary']; ?>
          <li>
            <span class="tl-icon sb-<?= e($st['color']) ?>"><i class="bi <?= $h['old_status'] === null ? 'bi-plus-circle' : ($h['old_status'] === $h['new_status'] ? 'bi-person-gear' : 'bi-arrow-right-circle') ?>"></i></span>
            <div class="min-w-0">
              <strong><?= $h['old_status'] === null ? 'Booking created' : ($h['old_status'] === $h['new_status'] ? 'Updated' : e(($statuses[$h['old_status']]['label'] ?? $h['old_status']) . ' → ' . $st['label'])) ?></strong>
              <?php if ($h['note']): ?><div class="tl-text"><?= e($h['note']) ?></div><?php endif; ?>
              <small class="d-block text-muted-2"><?= e($h['user_name'] ?? 'System') ?> · <?= e(admin_datetime($h['created_at'])) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="col-xl-4">
    <?php if ($canEdit): ?>
    <section class="panel mb-4" id="status">
      <div class="panel-head"><h2>Update status</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/bookings/status.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <select class="form-select mb-2" name="status" id="bkStatus" aria-label="Status" data-reason-for="cancelled">
          <?php foreach ($statuses as $slug => $s): ?><option value="<?= e($slug) ?>"<?= $slug === $b['status'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?>
        </select>
        <textarea class="form-control mb-2" name="note" rows="2" maxlength="500" placeholder="Note (required when cancelling)" aria-label="Note"></textarea>
        <div class="form-alert" data-form-alert></div>
        <button class="btn btn-grad w-100" type="submit">Save status</button>
        <p class="form-text mb-0 mt-2">Completing a job sets the final amount (if empty), warranty date and updates the lead.</p>
      </form>
    </section>

    <?php if (!(int) $b['is_closed']): ?>
    <section class="panel mb-4" id="technician">
      <div class="panel-head"><h2>Technician</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/bookings/assign.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <div class="input-group">
          <select class="form-select" name="technician_id" aria-label="Technician">
            <option value="">— Not assigned —</option>
            <?php foreach (technician_day_load($b['scheduled_date'], (int) $b['id']) as $t): ?>
              <option value="<?= $t['id'] ?>"<?= (int) $b['technician_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?> · <?= $t['jobs'] ?> other job<?= $t['jobs'] === 1 ? '' : 's' ?><?= in_array($b['scheduled_time'], $t['slots'], true) ? ' ⚠ same slot' : '' ?><?= $t['status'] === 'on_leave' ? ' (on leave)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-ghost" type="submit">Save</button>
        </div>
        <div class="form-alert mt-2" data-form-alert></div>
      </form>
    </section>

    <section class="panel mb-4" id="reschedule">
      <div class="panel-head"><h2>Reschedule</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/bookings/reschedule.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label" for="rsDate">New date</label><input class="form-control" type="date" id="rsDate" name="date" min="<?= date('Y-m-d') ?>" value="<?= e(max($b['scheduled_date'], date('Y-m-d'))) ?>" required></div>
          <div class="col-6"><label class="form-label" for="rsTime">Slot</label><select class="form-select" id="rsTime" name="time" required><?= select_options(array_combine($slots, $slots), $b['scheduled_time']) ?></select></div>
        </div>
        <input class="form-control mb-2" name="reason" maxlength="255" placeholder="Reason, e.g. customer not at home" aria-label="Reason">
        <div class="form-alert" data-form-alert></div>
        <button class="btn btn-ghost w-100" type="submit"><i class="bi bi-arrow-clockwise"></i> Reschedule</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel mb-4" id="amounts">
      <div class="panel-head"><h2>Amounts &amp; notes</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/bookings/amounts.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <div class="row g-2 mb-2">
          <?php foreach (['estimated_amount' => 'Estimated', 'quoted_amount' => 'Quoted', 'discount_amount' => 'Discount', 'final_amount' => 'Final'] as $k => $label): ?>
            <div class="col-6"><label class="form-label" for="am<?= $k ?>"><?= $label ?> (₹)</label><input class="form-control" id="am<?= $k ?>" name="<?= $k ?>" inputmode="decimal" value="<?= e($b[$k] !== null ? rtrim(rtrim((string) $b[$k], '0'), '.') : '') ?>"></div>
          <?php endforeach; ?>
        </div>
        <label class="form-label" for="amNotes">Notes</label>
        <textarea class="form-control mb-2" id="amNotes" name="notes" rows="3" maxlength="2000"><?= e($b['notes']) ?></textarea>
        <div class="form-alert" data-form-alert></div>
        <button class="btn btn-ghost w-100" type="submit">Save amounts</button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
