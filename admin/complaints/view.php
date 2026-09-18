<?php
/** One complaint: details, job & feedback, history, and actions (status, owner, note, revisit). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_permission('complaints.view');

$c = complaint_find(clean_str($_GET['number'] ?? '', 20));
if (!$c) {
    http_response_code(404);
    $admin = ['title' => 'Complaint not found', 'active' => 'complaints'];
    require ROOT_PATH . '/includes/admin/header.php';
    echo '<div class="empty-state"><i class="bi bi-search"></i><h2>Complaint not found</h2><a class="btn btn-grad" href="' . e(url('admin/complaints')) . '">Back</a></div>';
    require ROOT_PATH . '/includes/admin/footer.php';
    exit;
}
$self = url('admin/complaints/view?number=' . $c['complaint_number']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('complaints.manage');
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect($self);
    }
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'status') {
            complaint_change_status($c, (string) ($_POST['status'] ?? ''), clean_str($_POST['note'] ?? '', 2000), (int) $me['id']);
            flash_set('success', 'Status updated.');
        } elseif ($op === 'assign') {
            complaint_assign($c, (int) ($_POST['assigned_to'] ?? 0) ?: null, (int) $me['id']);
            flash_set('success', 'Owner updated.');
        } elseif ($op === 'note') {
            $note = clean_str($_POST['note'] ?? '', 2000);
            if (mb_strlen($note) < 2) throw new InvalidArgumentException('Write a note first');
            complaint_add_update($c, (int) $me['id'], ($_POST['kind'] ?? '') === 'customer' ? 'customer' : 'note', $note);
            if ($c['status'] === 'open') complaint_change_status($c, 'in_progress', 'Customer contacted', (int) $me['id']);
            flash_set('success', 'Note added.');
        } elseif ($op === 'revisit') {
            require_permission('bookings.create');
            if ($c['revisit_booking_id']) throw new InvalidArgumentException('A revisit is already booked for this complaint');
            $date = (string) ($_POST['scheduled_date'] ?? '');
            $d = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) throw new InvalidArgumentException('Pick today or a future date');
            $time = clean_str($_POST['scheduled_time'] ?? '', 40);
            if ($time === '') throw new InvalidArgumentException('Choose a time slot');
            $address = clean_str($_POST['address'] ?? '', 500);
            if (mb_strlen($address) < 5 && !$c['booking_id']) throw new InvalidArgumentException('Enter the visit address');
            $techId = (int) ($_POST['technician_id'] ?? 0) ?: null;
            $bk = complaint_schedule_revisit($c, ['scheduled_date' => $date, 'scheduled_time' => $time, 'address' => $address, 'technician_id' => $techId], (int) $me['id']);
            flash_set('success', "Revisit booked: {$bk['booking_number']}" . ($c['is_warranty'] ? ' (free — warranty)' : '') . '.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage() . '.');
    }
    redirect($self);
}

$updates = db_all('SELECT cu.*, u.name AS user_name FROM complaint_updates cu LEFT JOIN users u ON u.id = cu.user_id WHERE cu.complaint_id = ? ORDER BY cu.id DESC', [$c['id']]);
$history = $c['customer_id'] ? db_all('SELECT complaint_number, category, status, created_at FROM complaints WHERE customer_id = ? AND id <> ? ORDER BY id DESC LIMIT 5', [$c['customer_id'], $c['id']]) : [];
$canManage = can('complaints.manage');
$open = in_array($c['status'], COMPLAINT_OPEN, true);
$slots = time_slots();
$overdue = $open && $c['status'] !== 'revisit_scheduled' && $c['sla_due_at'] && strtotime($c['sla_due_at']) < time();
$origAddress = $c['booking_id'] ? db_value('SELECT address FROM bookings WHERE id = ?', [$c['booking_id']]) : '';
$waText = 'Hi ' . strtok($c['customer_name'] ?: 'there', ' ') . ', this is ' . setting('business_name') . ' about your complaint ' . $c['complaint_number'] . '. ';

$actions = '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $c['phone'])) . '"><i class="bi bi-telephone"></i> Call customer</a>'
    . '<a class="btn btn-wa btn-sm" href="' . e(whatsapp_link($waText, '91' . $c['phone'])) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
if ($c['technician_phone']) $actions .= '<a class="btn btn-ghost btn-sm" href="' . e(tel_link('+91' . $c['technician_phone'])) . '"><i class="bi bi-person-gear"></i> Call technician</a>';
$admin = ['title' => COMPLAINT_CATEGORIES[$c['category']] . ' · ' . $c['customer_name'], 'active' => 'complaints', 'actions' => $actions];
require ROOT_PATH . '/includes/admin/header.php';
$icon = ['created' => 'bi-flag', 'note' => 'bi-sticky', 'status' => 'bi-arrow-right-circle', 'assign' => 'bi-person-check', 'revisit' => 'bi-calendar-plus', 'customer' => 'bi-telephone'];
?>
<div class="lead-meta mb-3">
  <a class="text-muted-2 small" href="<?= e(url('admin/complaints')) ?>"><i class="bi bi-arrow-left"></i> Complaints</a>
  <span class="mono fw-semibold"><?= e($c['complaint_number']) ?></span>
  <?= status_badge(COMPLAINT_STATUSES[$c['status']], COMPLAINT_STATUS_COLORS[$c['status']]) ?>
  <?= $c['priority'] !== 'normal' ? priority_badge($c['priority']) : '' ?>
  <?php if ($c['is_warranty']): ?><span class="tag"><i class="bi bi-shield-check"></i> Under warranty — revisit is free</span><?php endif; ?>
  <span class="text-muted-2 small ms-auto">Raised <?= e(admin_datetime($c['created_at'])) ?> via <?= e($c['source']) ?></span>
</div>
<?php if ($overdue): ?>
  <div class="alert alert-danger"><i class="bi bi-alarm"></i> <strong>SLA crossed.</strong> This had to be handled by <?= e(admin_datetime($c['sla_due_at'])) ?>. Call the customer now.</div>
<?php elseif ($open && $c['sla_due_at'] && $c['status'] !== 'revisit_scheduled'): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-hourglass-split"></i> Respond by <strong><?= e(admin_datetime($c['sla_due_at'])) ?></strong></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-xl-8">
    <section class="panel mb-4">
      <div class="panel-head"><h2>What the customer said</h2></div>
      <div class="panel-body">
        <div class="quote-box mb-3"><small><?= e(COMPLAINT_CATEGORIES[$c['category']]) ?></small><?= nl2br(e($c['description'] ?: '—')) ?></div>
        <?php if ($c['feedback_rating']): ?><p class="small mb-0"><i class="bi bi-star-fill text-warning"></i> Came from a <strong><?= (int) $c['feedback_rating'] ?>★ rating</strong> after the job.</p><?php endif; ?>
      </div>
    </section>

    <section class="panel mb-4">
      <div class="panel-head"><h2>Customer &amp; job</h2></div>
      <div class="panel-body row g-4">
        <dl class="col-md-6 kv-list mb-0">
          <div class="kv"><dt>Customer</dt><dd><?= $c['customer_number'] ? '<a href="' . e(url('admin/customers/view?number=' . $c['customer_number'])) . '">' . e($c['customer_name']) . '</a>' : e($c['customer_name']) ?></dd></div>
          <div class="kv"><dt>Phone</dt><dd><a href="<?= e(tel_link('+91' . $c['phone'])) ?>"><?= e($c['phone']) ?></a></dd></div>
          <div class="kv"><dt>Email</dt><dd><?= e($c['email'] ?: '—') ?></dd></div>
        </dl>
        <dl class="col-md-6 kv-list mb-0">
          <div class="kv"><dt>Job</dt><dd><?= $c['booking_number'] ? '<a class="mono" href="' . e(url('admin/bookings/view?number=' . $c['booking_number'])) . '">' . e($c['booking_number']) . '</a> · ' . e($c['service_name'] ?? '') : '<span class="text-muted-2">Not linked</span>' ?></dd></div>
          <div class="kv"><dt>Job date</dt><dd><?= $c['job_date'] ? e(date('d M Y', strtotime($c['job_date']))) : '—' ?></dd></div>
          <div class="kv"><dt>Warranty till</dt><dd><?= $c['warranty_until'] ? e(date('d M Y', strtotime($c['warranty_until']))) . ($c['is_warranty'] ? ' <span class="status-badge sb-success">Valid</span>' : ' <span class="status-badge sb-secondary">Expired</span>') : '—' ?></dd></div>
          <div class="kv"><dt>Technician</dt><dd><?= e($c['technician_name'] ?? '—') ?></dd></div>
          <?php if ($c['revisit_number']): ?><div class="kv"><dt>Revisit</dt><dd><a class="mono" href="<?= e(url('admin/bookings/view?number=' . $c['revisit_number'])) ?>"><?= e($c['revisit_number']) ?></a> · <?= e(date('d M', strtotime($c['revisit_date']))) ?> · <?= e($c['revisit_status']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($c['resolution']): ?><div class="col-12"><div class="quote-box"><small>Resolution</small><?= nl2br(e($c['resolution'])) ?></div></div><?php endif; ?>
      </div>
    </section>

    <section class="panel mb-4">
      <div class="panel-head"><h2>History</h2></div>
      <ul class="timeline timeline-lg">
        <?php foreach ($updates as $u): ?>
          <li>
            <span class="tl-icon sb-<?= $u['type'] === 'status' ? e(COMPLAINT_STATUS_COLORS[$u['new_status']] ?? 'secondary') : 'indigo' ?>"><i class="bi <?= e($icon[$u['type']] ?? 'bi-dot') ?>"></i></span>
            <div class="min-w-0">
              <strong><?= $u['type'] === 'status' ? e((COMPLAINT_STATUSES[$u['old_status']] ?? $u['old_status']) . ' → ' . (COMPLAINT_STATUSES[$u['new_status']] ?? $u['new_status'])) : e(['created' => 'Complaint registered', 'note' => 'Note', 'assign' => 'Owner changed', 'revisit' => 'Revisit booked', 'customer' => 'Customer contacted'][$u['type']]) ?></strong>
              <?php if ($u['note']): ?><div class="tl-text"><?= nl2br(e($u['note'])) ?></div><?php endif; ?>
              <small class="d-block text-muted-2"><?= e($u['user_name'] ?? 'Customer / system') ?> · <?= e(admin_datetime($u['created_at'])) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="col-xl-4">
    <?php if ($canManage): ?>
      <section class="panel mb-4">
        <div class="panel-head"><h2>Update</h2></div>
        <form method="post" class="panel-body">
          <?= csrf_field() ?><input type="hidden" name="op" value="status">
          <label class="form-label" for="cStatus">Status</label>
          <select class="form-select mb-2" id="cStatus" name="status"><?= select_options(COMPLAINT_STATUSES, $c['status']) ?></select>
          <textarea class="form-control mb-2" name="note" rows="3" maxlength="2000" placeholder="What was done? (required for Resolved / Rejected — the customer sees it in the email)" aria-label="Note"></textarea>
          <button class="btn btn-grad w-100" type="submit">Save status</button>
        </form>
      </section>

      <section class="panel mb-4">
        <div class="panel-head"><h2>Owner</h2></div>
        <form method="post" class="panel-body">
          <?= csrf_field() ?><input type="hidden" name="op" value="assign">
          <div class="input-group">
            <select class="form-select" name="assigned_to" aria-label="Owner"><option value="">— Unassigned —</option>
              <?php foreach (lead_staff_options() as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $c['assigned_to'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role_name']) ?>)</option><?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit">Save</button>
          </div>
        </form>
      </section>

      <?php if (!$c['revisit_booking_id'] && $open && can('bookings.create')): ?>
      <section class="panel mb-4" id="revisit">
        <div class="panel-head"><h2><?= $c['is_warranty'] ? 'Free warranty revisit' : 'Book a revisit' ?></h2></div>
        <form method="post" class="panel-body">
          <?= csrf_field() ?><input type="hidden" name="op" value="revisit">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label" for="rvDate">Date</label><input class="form-control" id="rvDate" type="date" name="scheduled_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required></div>
            <div class="col-6"><label class="form-label" for="rvTime">Slot</label><select class="form-select" id="rvTime" name="scheduled_time" required><?= select_options(array_combine($slots, $slots), $slots[0] ?? '') ?></select></div>
          </div>
          <label class="form-label" for="rvTech">Technician</label>
          <select class="form-select mb-2" id="rvTech" name="technician_id">
            <option value="">Assign later</option>
            <?php foreach (lead_technician_options() as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $c['technician_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?><?= (int) $c['technician_id'] === (int) $t['id'] ? ' (did the original job)' : '' ?></option><?php endforeach; ?>
          </select>
          <label class="form-label" for="rvAddr">Address</label>
          <input class="form-control mb-3" id="rvAddr" name="address" value="<?= e($origAddress) ?>" maxlength="500">
          <button class="btn btn-grad w-100" type="submit"><i class="bi bi-calendar-plus"></i> Book revisit<?= $c['is_warranty'] ? ' (₹0)' : '' ?></button>
        </form>
      </section>
      <?php endif; ?>

      <section class="panel mb-4">
        <div class="panel-head"><h2>Add note</h2></div>
        <form method="post" class="panel-body">
          <?= csrf_field() ?><input type="hidden" name="op" value="note">
          <select class="form-select mb-2" name="kind" aria-label="Note type"><option value="customer">Spoke to customer</option><option value="note">Internal note</option></select>
          <textarea class="form-control mb-2" name="note" rows="3" maxlength="2000" required placeholder="What did the customer say? What’s the plan?" aria-label="Note"></textarea>
          <button class="btn btn-ghost w-100" type="submit">Add note</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($history): ?>
      <section class="panel mb-4">
        <div class="panel-head"><h2>Earlier complaints</h2></div>
        <ul class="list-rows">
          <?php foreach ($history as $h): ?>
            <li><div class="flex-grow-1"><a class="mono" href="<?= e(url('admin/complaints/view?number=' . $h['complaint_number'])) ?>"><?= e($h['complaint_number']) ?></a><small class="d-block text-muted-2"><?= e(COMPLAINT_CATEGORIES[$h['category']]) ?> · <?= e(date('d M Y', strtotime($h['created_at']))) ?></small></div>
              <?= status_badge(COMPLAINT_STATUSES[$h['status']], COMPLAINT_STATUS_COLORS[$h['status']]) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
