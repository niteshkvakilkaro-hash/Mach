<?php
/** Customer ratings after each job: overall score, per technician, and every comment. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/models/reports.php';
$me = require_permission('complaints.view');

$r = report_range($_GET + ['from' => date('Y-m-01', strtotime('-2 months'))]);
$rating = (int) ($_GET['rating'] ?? 0);
$tech = (int) ($_GET['technician'] ?? 0);
$stats = feedback_stats($r['from'], $r['to']);
$byTech = db_all(
    "SELECT t.id, t.name, COUNT(f.id) AS requested, SUM(f.status = 'submitted') AS submitted, ROUND(AVG(f.rating), 2) AS avg_rating,
            SUM(f.rating <= 3) AS unhappy, (SELECT COUNT(*) FROM complaints c WHERE c.technician_id = t.id AND c.created_at BETWEEN ? AND ?) AS complaints
     FROM feedback f JOIN technicians t ON t.id = f.technician_id
     WHERE f.requested_at BETWEEN ? AND ? GROUP BY t.id ORDER BY AVG(f.rating) IS NULL, AVG(f.rating) DESC",
    [$r['start'], $r['end'], $r['start'], $r['end']]
);
$where = "f.status = 'submitted' AND f.submitted_at BETWEEN ? AND ?";
$params = [$r['start'], $r['end']];
if ($rating) { $where .= ' AND f.rating = ?'; $params[] = $rating; }
if ($tech) { $where .= ' AND f.technician_id = ?'; $params[] = $tech; }
$list = db_all(
    "SELECT f.*, b.booking_number, s.name AS service_name, c.name AS customer_name, t.name AS technician_name, cm.complaint_number, cm.status AS complaint_status
     FROM feedback f JOIN bookings b ON b.id = f.booking_id JOIN customers c ON c.id = f.customer_id
     LEFT JOIN services s ON s.id = b.service_id LEFT JOIN technicians t ON t.id = f.technician_id LEFT JOIN complaints cm ON cm.id = f.complaint_id
     WHERE $where ORDER BY f.submitted_at DESC LIMIT 100",
    $params
);
$dist = array_column(db_all("SELECT rating, COUNT(*) n FROM feedback WHERE status = 'submitted' AND submitted_at BETWEEN ? AND ? GROUP BY rating", [$r['start'], $r['end']]), 'n', 'rating');
$tagLabels = FEEDBACK_TAGS_GOOD + FEEDBACK_TAGS_BAD;
$q = ['from' => $r['from'], 'to' => $r['to'], 'rating' => $rating ?: null, 'technician' => $tech ?: null];
$stars = fn($n) => '<span class="text-warning">' . str_repeat('★', (int) $n) . '</span><span class="text-muted-2">' . str_repeat('★', 5 - (int) $n) . '</span>';

$admin = ['title' => 'Feedback & ratings', 'subtitle' => date('d M Y', strtotime($r['from'])) . ' – ' . date('d M Y', strtotime($r['to'])), 'active' => 'feedback',
          'actions' => can('settings.manage') ? '<a class="btn btn-ghost btn-sm" href="' . e(url('admin/settings?tab=quality')) . '"><i class="bi bi-gear"></i> Feedback settings</a>' : ''];
require ROOT_PATH . '/includes/admin/header.php';
?>
<?php if (!str_starts_with(setting('google_review_url'), 'https://') && can('settings.manage')): ?>
  <div class="alert alert-warning"><i class="bi bi-google"></i> Add your <strong>Google review link</strong> in <a href="<?= e(url('admin/settings?tab=quality')) ?>">Settings → Feedback &amp; complaints</a> so happy customers are asked to review you on Google.</div>
<?php endif; ?>

<form class="d-flex flex-wrap gap-2 align-items-center mb-3" method="get" action="<?= e(url('admin/feedback')) ?>">
  <input class="form-control form-control-sm" type="date" name="from" value="<?= e($r['from']) ?>" style="max-width:150px" aria-label="From">
  <input class="form-control form-control-sm" type="date" name="to" value="<?= e($r['to']) ?>" style="max-width:150px" aria-label="To">
  <select class="form-select form-select-sm" name="rating" style="max-width:140px" aria-label="Rating"><?= select_options([5 => '5 ★', 4 => '4 ★', 3 => '3 ★', 2 => '2 ★', 1 => '1 ★'], $rating ?: '', 'Any rating') ?></select>
  <select class="form-select form-select-sm" name="technician" style="max-width:200px" aria-label="Technician"><?= select_options(array_column(lead_technician_options(), 'name', 'id'), $tech ?: '', 'All technicians') ?></select>
  <button class="btn btn-grad btn-sm" type="submit">Apply</button>
</form>

<section class="kpi-grid mb-4">
  <div class="kpi kpi-accent"><span class="kpi-icon"><i class="bi bi-star-fill"></i></span><div class="kpi-body"><span class="kpi-label">Average rating</span><strong class="kpi-value"><?= $stats['avg_rating'] ? number_format((float) $stats['avg_rating'], 2) : '—' ?> <small class="text-muted-2 fs-6">/ 5</small></strong><span class="kpi-sub"><?= (int) $stats['submitted'] ?> ratings</span></div></div>
  <div class="kpi kpi-good"><span class="kpi-icon"><i class="bi bi-emoji-smile"></i></span><div class="kpi-body"><span class="kpi-label">Happy customers (4–5★)</span><strong class="kpi-value"><?= number_format($stats['happy_pct'], 1) ?>%</strong><span class="kpi-sub"><?= (int) $stats['five'] ?> gave 5★</span></div></div>
  <div class="kpi<?= $stats['unhappy'] ? ' kpi-danger' : '' ?>"><span class="kpi-icon"><i class="bi bi-emoji-frown"></i></span><div class="kpi-body"><span class="kpi-label">Unhappy (1–3★)</span><strong class="kpi-value"><?= (int) $stats['unhappy'] ?></strong><span class="kpi-sub">each opened a complaint</span></div></div>
  <div class="kpi"><span class="kpi-icon"><i class="bi bi-reply"></i></span><div class="kpi-body"><span class="kpi-label">Response rate</span><strong class="kpi-value"><?= number_format($stats['response_rate'], 1) ?>%</strong><span class="kpi-sub"><?= (int) $stats['submitted'] ?> of <?= (int) $stats['requested'] ?> jobs rated</span></div></div>
  <div class="kpi"><span class="kpi-icon"><i class="bi bi-google"></i></span><div class="kpi-body"><span class="kpi-label">Sent to Google</span><strong class="kpi-value"><?= (int) $stats['google'] ?></strong><span class="kpi-sub">clicked “Review us on Google”</span></div></div>
</section>

<div class="row g-4 mb-4">
  <div class="col-xl-4">
    <section class="panel h-100">
      <div class="panel-head"><h2>Rating split</h2></div>
      <div class="method-bars">
        <?php $maxD = max(1, max($dist ?: [0])); for ($s = 5; $s >= 1; $s--): $n = (int) ($dist[$s] ?? 0); ?>
          <div class="method-row">
            <a class="method-name text-reset" href="<?= e(admin_query_url('admin/feedback', $q, ['rating' => $s])) ?>"><?= $s ?> ★</a>
            <span class="method-track" role="img" aria-label="<?= $s ?> stars: <?= $n ?>"><i style="width:<?= round($n / $maxD * 100, 1) ?>%"></i></span>
            <span class="method-val"><?= $n ?></span>
          </div>
        <?php endfor; ?>
      </div>
    </section>
  </div>
  <div class="col-xl-8">
    <section class="panel h-100">
      <div class="panel-head"><h2>Technician ratings</h2><span class="small text-muted-2">Lowest ratings need a conversation</span></div>
      <?php if ($byTech): ?>
        <div class="table-responsive"><table class="table admin-table mb-0">
          <thead><tr><th>Technician</th><th>Rating</th><th class="text-end">Rated / jobs</th><th class="text-end">1–3★</th><th class="text-end">Complaints</th></tr></thead>
          <tbody>
            <?php foreach ($byTech as $t): ?>
              <tr>
                <td><a class="text-reset" href="<?= e(admin_query_url('admin/feedback', $q, ['technician' => $t['id']])) ?>"><strong><?= e($t['name']) ?></strong></a></td>
                <td><?= $t['avg_rating'] ? '<strong>' . e(number_format((float) $t['avg_rating'], 1)) . '</strong> ' . $stars(round((float) $t['avg_rating'])) : '<span class="text-muted-2">no ratings yet</span>' ?></td>
                <td class="text-end"><?= (int) $t['submitted'] ?> / <?= (int) $t['requested'] ?></td>
                <td class="text-end<?= $t['unhappy'] ? ' text-danger fw-semibold' : '' ?>"><?= (int) $t['unhappy'] ?></td>
                <td class="text-end<?= $t['complaints'] ? ' text-danger fw-semibold' : '' ?>"><?= (int) $t['complaints'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><div class="empty-mini">No completed jobs with feedback requests in this period.</div><?php endif; ?>
    </section>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>What customers said</h2><span class="small text-muted-2"><?= count($list) ?> shown</span></div>
  <?php if ($list): ?>
    <ul class="list-rows">
      <?php foreach ($list as $f): $low = (int) $f['rating'] <= (int) setting('feedback_low_rating', '3'); ?>
        <li class="align-items-start<?= $low ? ' fb-low' : '' ?>">
          <span class="fb-score<?= $low ? ' is-low' : '' ?>"><?= (int) $f['rating'] ?>★</span>
          <div class="flex-grow-1 min-w-0">
            <strong><?= e($f['customer_name']) ?></strong> · <?= e($f['service_name'] ?? 'Service') ?> · <a class="mono small" href="<?= e(url('admin/bookings/view?number=' . $f['booking_number'])) ?>"><?= e($f['booking_number']) ?></a>
            <small class="text-muted-2"> · <?= e($f['technician_name'] ?? '—') ?> · <?= e(time_ago($f['submitted_at'])) ?></small>
            <?php if ($f['tags']): ?><div class="mt-1"><?php foreach (explode(',', $f['tags']) as $tg): ?><span class="tag me-1<?= isset(FEEDBACK_TAGS_BAD[$tg]) ? ' tag-bad' : ' tag-good' ?>"><?= e($tagLabels[$tg] ?? $tg) ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($f['comment']): ?><div class="tl-text mt-1">“<?= e($f['comment']) ?>”</div><?php endif; ?>
            <?php if ($f['complaint_number']): ?><small class="d-block mt-1"><i class="bi bi-exclamation-diamond text-danger"></i> <a href="<?= e(url('admin/complaints/view?number=' . $f['complaint_number'])) ?>"><?= e($f['complaint_number']) ?></a> · <?= e(COMPLAINT_STATUSES[$f['complaint_status']] ?? '') ?></small><?php endif; ?>
          </div>
          <?php if ($f['google_clicked_at']): ?><span class="tag" title="Clicked Review on Google"><i class="bi bi-google"></i></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><div class="empty-mini">No ratings in this period yet. Ratings come in after jobs are marked Completed.</div><?php endif; ?>
</section>
<?php require ROOT_PATH . '/includes/admin/footer.php'; ?>
