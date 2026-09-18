<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('leads.view');

$lead = lead_find(clean_str($_GET['number'] ?? '', 20));
if (!$lead) {
    http_response_code(404);
    $admin = ['title' => 'Lead not found', 'active' => 'leads.all'];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2>Lead not found</h2><p>It may have been deleted, or it is assigned to someone else.</p><a class="btn btn-grad" href="' . e(url('admin/leads')) . '">Back to leads</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}

$statuses = lead_statuses();
$notes = lead_notes((int) $lead['id']);
$followups = lead_followups((int) $lead['id']);
$enquiries = lead_enquiries((int) $lead['id']);
$timeline = lead_timeline((int) $lead['id']);
$related = lead_related($lead);
$bookings = lead_bookings((int) $lead['id']);
$payments = can('payments.view') ? lead_payments((int) $lead['id']) : [];
$stages = lead_stage_progress($lead);
$pendingFollowups = count(array_filter($followups, fn($f) => $f['status'] === 'pending'));
$nextBooking = null;
foreach ($bookings as $b) {
    if ($b['scheduled_date'] >= date('Y-m-d')) $nextBooking = $b;
}
$canEdit = can('leads.edit');
$canAssign = can('leads.assign');
$waLink = whatsapp_link(lead_whatsapp_text($lead, $nextBooking), '91' . $lead['phone']);
$num = e($lead['lead_number']);

$actions = '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $lead['phone'])) . '"><i class="bi bi-telephone"></i> Call</a>'
    . '<a class="btn btn-wa btn-sm" href="' . e($waLink) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
if ($canEdit) {
    $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/leads/edit?number=' . rawurlencode($lead['lead_number']))) . '"><i class="bi bi-pencil"></i> Edit</a>';
}
if (can('bookings.create') && !(int) $lead['is_closed']) {
    $actions .= '<a class="btn btn-grad btn-sm" href="' . e(url('admin/bookings/create?lead=' . rawurlencode($lead['lead_number']))) . '"><i class="bi bi-calendar-plus"></i> Schedule booking</a>';
}
if ((int) $lead['is_closed'] && can('leads.create')) {
    $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/leads/edit?parent=' . rawurlencode($lead['lead_number']))) . '"><i class="bi bi-arrow-repeat"></i> Re-enquiry</a>';
}
if (can('leads.delete')) {
    $actions .= '<button class="btn btn-ghost btn-sm text-danger" type="button" data-delete-lead="' . $num . '" data-after-delete="' . e(url('admin/leads')) . '"><i class="bi bi-trash"></i></button>';
}

$admin = ['title' => $lead['customer_name'] ?: 'Lead ' . $lead['lead_number'], 'active' => 'leads.all', 'actions' => $actions];
$field = function (string $label, ?string $value, bool $raw = false) {
    echo '<div class="kv"><dt>' . e($label) . '</dt><dd>' . ($value !== null && $value !== '' ? ($raw ? $value : e($value)) : '<span class="text-muted-2">—</span>') . '</dd></div>';
};
require ROOT_PATH . '/includes/admin/header.php';
?>
<div class="lead-meta mb-3">
  <a class="text-muted-2 small" href="<?= e(url('admin/leads')) ?>"><i class="bi bi-arrow-left"></i> Leads</a>
  <span class="mono fw-semibold"><?= $num ?></span>
  <?= status_badge($lead['status_label'], $lead['status_color']) ?>
  <?= priority_badge($lead['priority']) ?>
  <?php if ($lead['enquiry_count'] > 1): ?><span class="tag"><i class="bi bi-arrow-repeat"></i> <?= (int) $lead['enquiry_count'] ?> enquiries</span><?php endif; ?>
  <?php if ($lead['is_repeat_customer']): ?><span class="tag">Repeat customer</span><?php endif; ?>
  <?php if ($lead['parent_number']): ?><a class="tag" href="<?= e(lead_url($lead['parent_number'])) ?>">Re-enquiry of <?= e($lead['parent_number']) ?></a><?php endif; ?>
  <span class="text-muted-2 small ms-auto">Created <?= e(admin_datetime($lead['created_at'])) ?><?= strtotime($lead['created_at']) > time() - 604800 ? ' · ' . e(time_ago($lead['created_at'])) : '' ?></span>
</div>

<!-- Stage tracker -->
<ol class="stage-track panel mb-4" aria-label="Lead progress">
  <?php foreach ($stages as $s): ?>
    <li class="<?= $s['done'] ? 'is-done' : '' ?><?= $s['current'] ? ' is-current' : '' ?>"<?= $s['current'] ? ' aria-current="step"' : '' ?>>
      <span class="st-dot"><?= $s['done'] ? '<i class="bi bi-check-lg"></i>' : '' ?></span><span class="st-label"><?= e($s['label']) ?></span>
    </li>
  <?php endforeach; ?>
</ol>
<?php if (in_array($lead['status'], LEAD_LOST_STATUSES, true)): ?>
  <div class="alert alert-secondary small"><i class="bi bi-info-circle"></i> Closed as <strong><?= e($lead['status_label']) ?></strong><?= $lead['lost_reason'] ? ': ' . e($lead['lost_reason']) : '' ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <!-- Details -->
    <section class="panel mb-4">
      <div class="panel-head"><h2>Customer &amp; request</h2><?php if ($lead['customer_number']): ?><span class="tag">Customer <?= e($lead['customer_number']) ?></span><?php endif; ?></div>
      <div class="panel-body">
        <div class="row g-4">
          <dl class="col-md-6 kv-list mb-0">
            <?php $field('Name', $lead['customer_name']); ?>
            <?php $field('Phone', '<a href="' . e(tel_link('+91' . $lead['phone'])) . '">' . e($lead['phone']) . '</a>', true); ?>
            <?php $field('Email', $lead['email'] ? '<a href="mailto:' . e($lead['email']) . '">' . e($lead['email']) . '</a>' : null, true); ?>
            <?php $field('Address', $lead['address']); ?>
            <?php $field('Area / City', trim(($lead['area_name'] ? $lead['area_name'] . ', ' : '') . ($lead['city'] ?? ''), ', ')); ?>
            <?php $field('Pincode', $lead['pincode']); ?>
          </dl>
          <dl class="col-md-6 kv-list mb-0">
            <?php $field('Service', $lead['service_name'] ? $lead['service_name'] . ($lead['category_name'] ? ' · ' . $lead['category_name'] : '') : null); ?>
            <?php $field('Appliance', trim(($lead['appliance'] ?? '') . ($lead['brand'] ? ' · ' . $lead['brand'] : ''), ' ·')); ?>
            <?php $field('Problem', $lead['problem_type']); ?>
            <?php $field('Preferred visit', $lead['preferred_date'] ? date('D, d M Y', strtotime($lead['preferred_date'])) . ($lead['preferred_time'] ? ' · ' . $lead['preferred_time'] : '') : null); ?>
            <?php $field('Assigned staff', $lead['assigned_name']); ?>
            <?php $field('Technician', $lead['technician_name'] ? $lead['technician_name'] . ' · ' . $lead['technician_phone'] : null); ?>
          </dl>
          <?php if ($lead['description']): ?>
            <div class="col-12"><div class="quote-box"><small>Customer's description</small><?= nl2br(e($lead['description'])) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Tabs -->
    <section class="panel mb-4">
      <ul class="nav panel-tabs" role="tablist">
        <li role="presentation"><button class="active" data-bs-toggle="tab" data-bs-target="#tabTimeline" type="button" role="tab" aria-controls="tabTimeline" aria-selected="true">Timeline <b><?= count($timeline) ?></b></button></li>
        <li role="presentation"><button id="notes" data-bs-toggle="tab" data-bs-target="#tabNotes" type="button" role="tab" aria-controls="tabNotes" aria-selected="false">Notes <b><?= count($notes) ?></b></button></li>
        <li role="presentation"><button id="followups" data-bs-toggle="tab" data-bs-target="#tabFollow" type="button" role="tab" aria-controls="tabFollow" aria-selected="false">Follow-ups <b><?= $pendingFollowups ?></b></button></li>
        <li role="presentation"><button data-bs-toggle="tab" data-bs-target="#tabEnq" type="button" role="tab" aria-controls="tabEnq" aria-selected="false">Enquiries <b><?= count($enquiries) ?></b></button></li>
        <li role="presentation"><button data-bs-toggle="tab" data-bs-target="#tabBook" type="button" role="tab" aria-controls="tabBook" aria-selected="false">Bookings &amp; payments <b><?= count($bookings) ?></b></button></li>
      </ul>
      <div class="tab-content">
        <!-- Timeline -->
        <div class="tab-pane fade show active" id="tabTimeline" role="tabpanel" tabindex="0">
          <ul class="timeline timeline-lg">
            <?php foreach ($timeline as $ev): ?>
              <li>
                <span class="tl-icon sb-<?= e($ev['color']) ?>"><i class="bi <?= e($ev['icon']) ?>"></i></span>
                <div class="min-w-0">
                  <strong><?= e($ev['title']) ?></strong>
                  <?php if ($ev['text']): ?><div class="tl-text"><?= nl2br(e($ev['text'])) ?></div><?php endif; ?>
                  <small class="d-block text-muted-2"><?= e($ev['by']) ?> · <?= e(admin_datetime($ev['at'])) ?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <!-- Notes -->
        <div class="tab-pane fade" id="tabNotes" role="tabpanel" tabindex="0">
          <?php if ($canEdit): ?>
            <form class="panel-body border-bottom-line" data-ajax action="<?= e(url('api/leads/note.php')) ?>">
              <input type="hidden" name="number" value="<?= $num ?>">
              <label class="form-label" for="noteText">Internal note <small class="text-muted-2 fw-normal">— visible to staff only</small></label>
              <textarea class="form-control mb-2" id="noteText" name="note" rows="3" maxlength="2000" required placeholder="What did the customer say? Anything the technician should know?"></textarea>
              <div class="form-alert" data-form-alert></div>
              <button class="btn btn-grad btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Add note</button>
            </form>
          <?php endif; ?>
          <?php if ($notes): ?>
            <ul class="list-rows">
              <?php foreach ($notes as $n): ?>
                <li class="align-items-start">
                  <span class="avatar avatar-sm"><?= e(initials($n['user_name'] ?? 'System')) ?></span>
                  <div class="min-w-0"><div class="note-text"><?= nl2br(e($n['note'])) ?></div><small class="text-muted-2"><?= e($n['user_name'] ?? 'System') ?> · <?= e(admin_datetime($n['created_at'])) ?></small></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?><div class="empty-mini">No notes yet.</div><?php endif; ?>
        </div>

        <!-- Follow-ups -->
        <div class="tab-pane fade" id="tabFollow" role="tabpanel" tabindex="0">
          <?php if ($followups): ?>
            <ul class="list-rows">
              <?php foreach ($followups as $f):
                  $late = $f['status'] === 'pending' && ($f['followup_date'] < date('Y-m-d') || ($f['followup_date'] === date('Y-m-d') && $f['followup_time'] && $f['followup_time'] < date('H:i:s'))); ?>
                <li>
                  <span class="row-icon<?= $late ? ' is-late' : '' ?>"><i class="bi <?= $f['type'] === 'whatsapp' ? 'bi-whatsapp' : ($f['type'] === 'visit' ? 'bi-geo-alt' : 'bi-alarm') ?>"></i></span>
                  <div class="flex-grow-1 min-w-0">
                    <strong><?= e(FOLLOWUP_TYPES[$f['type']] ?? $f['type']) ?></strong> ·
                    <span class="<?= $late ? 'text-danger' : '' ?>"><?= e(date('D, d M', strtotime($f['followup_date']))) ?><?= $f['followup_time'] ? ', ' . e(date('h:i A', strtotime($f['followup_time']))) : '' ?><?= $late ? ' (overdue)' : '' ?></span>
                    <?php if ($f['note']): ?><small class="d-block text-muted-2"><?= e($f['note']) ?></small><?php endif; ?>
                    <small class="d-block text-muted-2">
                      <?= $f['assignee'] ? 'For ' . e($f['assignee']) : 'Unassigned' ?>
                      <?php if ($f['status'] !== 'pending'): ?> · <?= e(ucfirst($f['status'])) ?> by <?= e($f['completed_by_name'] ?? '—') ?><?= $f['outcome'] ? ': ' . e($f['outcome']) : '' ?><?php endif; ?>
                    </small>
                  </div>
                  <?php if ($f['status'] === 'pending' && $canEdit): ?>
                    <div class="d-flex gap-1">
                      <button class="btn btn-ghost btn-sm" type="button" data-followup-close="<?= (int) $f['id'] ?>" data-status="completed"><i class="bi bi-check2"></i> Done</button>
                      <button class="btn btn-ghost btn-sm" type="button" data-followup-close="<?= (int) $f['id'] ?>" data-status="cancelled" aria-label="Cancel follow-up"><i class="bi bi-x"></i></button>
                    </div>
                  <?php else: ?>
                    <span class="status-badge sb-<?= $f['status'] === 'completed' ? 'success' : ($f['status'] === 'cancelled' ? 'secondary' : ($late ? 'danger' : 'warning')) ?>"><?= e(ucfirst($f['status'])) ?></span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?><div class="empty-mini">No follow-ups scheduled. Use the form on the right to add one.</div><?php endif; ?>
        </div>

        <!-- Enquiries -->
        <div class="tab-pane fade" id="tabEnq" role="tabpanel" tabindex="0">
          <ul class="list-rows">
            <?php foreach ($enquiries as $i => $en): ?>
              <li class="align-items-start">
                <span class="row-icon"><i class="bi <?= $i === count($enquiries) - 1 ? 'bi-inbox' : 'bi-arrow-repeat' ?>"></i></span>
                <div class="flex-grow-1 min-w-0">
                  <strong><?= e($en['service_name'] ?? 'No service') ?></strong> <span class="text-muted-2 small">via <?= e($en['form_type']) ?> form · <?= e(LEAD_SOURCES[$en['source']] ?? $en['source']) ?></span>
                  <?php if ($en['problem_type'] || $en['message']): ?><div class="small"><?= e(trim(($en['problem_type'] ?? '') . ($en['message'] ? ' — ' . $en['message'] : ''), ' —')) ?></div><?php endif; ?>
                  <small class="d-block text-muted-2"><?= e(admin_datetime($en['created_at'])) ?><?= $en['preferred_date'] ? ' · wants ' . e(date('d M', strtotime($en['preferred_date']))) . ($en['preferred_time'] ? ', ' . e($en['preferred_time']) : '') : '' ?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <!-- Bookings -->
        <div class="tab-pane fade" id="tabBook" role="tabpanel" tabindex="0">
          <?php if ($bookings): ?>
            <div class="table-responsive">
              <table class="table admin-table mb-0">
                <thead><tr><th>Booking</th><th>Scheduled</th><th>Technician</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                  <?php foreach ($bookings as $b): ?>
                    <tr>
                      <td><a class="mono" href="<?= e(url('admin/bookings/view?number=' . $b['booking_number'])) ?>"><?= e($b['booking_number']) ?></a></td>
                      <td><?= e(date('d M Y', strtotime($b['scheduled_date']))) ?><small class="d-block text-muted-2"><?= e($b['scheduled_time']) ?></small></td>
                      <td><?= e($b['technician_name'] ?? '—') ?></td>
                      <td><?= status_badge($b['status_label'], $b['status_color']) ?></td>
                      <td class="text-end"><?= e(format_inr($b['final_amount'] ?? $b['estimated_amount'])) ?><small class="d-block text-muted-2"><?= e(ucfirst($b['payment_status'])) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($payments): ?>
              <div class="panel-subhead">Payments</div>
              <ul class="list-rows">
                <?php foreach ($payments as $p): ?>
                  <li><span class="row-icon"><i class="bi bi-currency-rupee"></i></span>
                    <div class="flex-grow-1"><strong><?= e(format_inr($p['amount'])) ?></strong> · <?= e(strtoupper(str_replace('_', ' ', $p['payment_method']))) ?> <small class="d-block text-muted-2 mono"><?= e($p['payment_number']) ?> · <?= e($p['booking_number']) ?></small></div>
                    <small class="text-muted-2"><?= e(admin_datetime($p['payment_date'], false)) ?></small>
                    <?= status_badge(ucfirst($p['status']), $p['status'] === 'paid' ? 'success' : ($p['status'] === 'pending' ? 'warning' : 'danger')) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php else: ?><div class="empty-mini">No bookings yet. Use “Schedule booking” above.</div><?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <!-- Side column -->
  <div class="col-xl-4">
    <?php if ($canEdit): ?>
    <section class="panel mb-4" id="status">
      <div class="panel-head"><h2>Update status</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/leads/status.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <label class="form-label" for="stSel">Status</label>
        <select class="form-select mb-2" id="stSel" name="status" data-lost="<?= e(implode(',', LEAD_LOST_STATUSES)) ?>">
          <?php foreach ($statuses as $slug => $s): ?><option value="<?= e($slug) ?>"<?= $slug === $lead['status'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label" for="stNote">Note <small class="text-muted-2 fw-normal" data-reason-hint>(optional)</small></label>
        <textarea class="form-control mb-2" id="stNote" name="note" rows="2" maxlength="500" placeholder="e.g. Customer asked to call after 6 PM"></textarea>
        <div class="form-alert" data-form-alert></div>
        <button class="btn btn-grad w-100" type="submit">Save status</button>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canAssign): ?>
    <section class="panel mb-4" id="assign">
      <div class="panel-head"><h2>Assignment</h2></div>
      <div class="panel-body">
        <form data-ajax action="<?= e(url('api/leads/assign.php')) ?>" class="mb-3">
          <input type="hidden" name="number" value="<?= $num ?>">
          <label class="form-label" for="asStaff">Staff (sales / support)</label>
          <div class="input-group">
            <select class="form-select" id="asStaff" name="user_id">
              <option value="">— Unassigned —</option>
              <?php foreach (lead_staff_options() as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $lead['assigned_to'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role_name']) ?>)</option><?php endforeach; ?>
            </select>
            <select class="form-select" name="type" aria-label="Assignment type" style="max-width:110px">
              <option value="sales">Sales</option><option value="support">Support</option>
            </select>
            <button class="btn btn-ghost" type="submit">Save</button>
          </div>
          <div class="form-alert mt-2" data-form-alert></div>
        </form>
        <form data-ajax action="<?= e(url('api/leads/assign.php')) ?>">
          <input type="hidden" name="number" value="<?= $num ?>">
          <input type="hidden" name="type" value="technician">
          <label class="form-label" for="asTech">Technician</label>
          <div class="input-group">
            <select class="form-select" id="asTech" name="technician_id">
              <option value="">— None —</option>
              <?php foreach (lead_technician_options() as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $lead['technician_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?><?= $t['specialization'] ? ' · ' . e($t['specialization']) : '' ?><?= $t['status'] === 'on_leave' ? ' (on leave)' : '' ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit">Save</button>
          </div>
          <div class="form-text">Assigning a technician moves early-stage leads to “Technician Assigned”.</div>
          <div class="form-alert mt-2" data-form-alert></div>
        </form>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <section class="panel mb-4" id="followup">
      <div class="panel-head"><h2>Schedule follow-up</h2></div>
      <form class="panel-body" data-ajax action="<?= e(url('api/leads/followup.php')) ?>">
        <input type="hidden" name="number" value="<?= $num ?>">
        <input type="hidden" name="action" value="create">
        <div class="row g-2 mb-2">
          <div class="col-7"><label class="form-label" for="fuDate">Date</label><input class="form-control" type="date" id="fuDate" name="date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>"></div>
          <div class="col-5"><label class="form-label" for="fuTime">Time</label><input class="form-control" type="time" id="fuTime" name="time" value="11:00"></div>
          <div class="col-6"><label class="form-label" for="fuType">Type</label><select class="form-select" id="fuType" name="type"><?= select_options(FOLLOWUP_TYPES, 'call') ?></select></div>
          <div class="col-6"><label class="form-label" for="fuUser">For</label>
            <select class="form-select" id="fuUser" name="assigned_to">
              <?php foreach (lead_staff_options() as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($lead['assigned_to'] ?: $me['id']) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="form-label" for="fuNote">Note</label>
        <input class="form-control mb-2" id="fuNote" name="note" maxlength="500" placeholder="e.g. Share quotation for gas refill">
        <div class="form-alert" data-form-alert></div>
        <button class="btn btn-grad w-100" type="submit"><i class="bi bi-alarm"></i> Add follow-up</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel mb-4">
      <div class="panel-head"><h2>Source &amp; tracking</h2></div>
      <dl class="panel-body kv-list kv-compact mb-0">
        <?php $field('Source', LEAD_SOURCES[$lead['source']] ?? $lead['source']); ?>
        <?php $field('Form', $lead['form_type'] === 'admin' ? 'Added by ' . ($lead['created_by_name'] ?? 'staff') : ucfirst($lead['form_type']) . ' form'); ?>
        <?php foreach (['utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign', 'utm_term' => 'UTM term', 'utm_content' => 'UTM content'] as $k => $label): if ($lead[$k]) $field($label, $lead[$k]); endforeach; ?>
        <?php if ($lead['gclid']): $field('Google click ID', mb_strimwidth($lead['gclid'], 0, 28, '…')); endif; ?>
        <?php if ($lead['fbclid']): $field('Facebook click ID', mb_strimwidth($lead['fbclid'], 0, 28, '…')); endif; ?>
        <?php $field('Landing page', $lead['landing_page'] ? '<span class="text-break">' . e(preg_replace('#^https?://[^/]+#', '', $lead['landing_page'])) . '</span>' : null, true); ?>
        <?php $field('Referrer', $lead['referrer'] ? parse_url($lead['referrer'], PHP_URL_HOST) : null); ?>
        <?php $field('IP address', $lead['ip_address']); ?>
        <?php $field('Last enquiry', admin_datetime($lead['last_enquiry_at'])); ?>
      </dl>
    </section>

    <?php if ($related): ?>
    <section class="panel mb-4">
      <div class="panel-head"><h2>Other leads from this number</h2></div>
      <ul class="list-rows">
        <?php foreach ($related as $r): ?>
          <li>
            <div class="flex-grow-1 min-w-0"><a class="mono" href="<?= e(lead_url($r['lead_number'])) ?>"><?= e($r['lead_number']) ?></a><small class="d-block text-muted-2"><?= e($r['service_name'] ?? '—') ?> · <?= e(date('d M Y', strtotime($r['created_at']))) ?></small></div>
            <?= status_badge($r['status_label'], $r['status_color']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php require ROOT_PATH . '/includes/admin/partials/delete-modal.php'; ?>
<script type="application/json" id="leadContext"><?= js_json(['number' => $lead['lead_number']]) ?></script>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
