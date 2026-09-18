<?php
/** Technician app home: today's jobs (plus overdue open ones), upcoming, done. */
require dirname(__DIR__) . '/includes/bootstrap.php';
[$me, $tech] = tech_require();

$views = ['today' => 'Today', 'upcoming' => 'Upcoming', 'done' => 'Done'];
$view = array_key_exists($_GET['view'] ?? '', $views) ? $_GET['view'] : 'today';
$jobs = tech_jobs((int) $tech['id'], $view);
$openToday = count(array_filter($jobs, fn($j) => !(int) $j['is_closed']));

$appTitle = $view === 'today' ? 'Today · ' . date('D, d M') : $views[$view] . ' jobs';
$appTab = $view;
require ROOT_PATH . '/includes/technician/header.php';
?>
<?php if ($view === 'today'): ?>
  <div class="tech-hello">
    <span>Namaste, <?= e(strtok($tech['name'], ' ')) ?> 👋</span>
    <strong><?= $openToday ?> job<?= $openToday === 1 ? '' : 's' ?> to do today</strong>
    <?php $myRating = technician_ratings()[$tech['id']] ?? null; if ($myRating): ?><span class="d-block mt-1">Your customer rating: <strong class="text-warning">★ <?= e($myRating['avg_rating']) ?></strong> from <?= (int) $myRating['ratings'] ?> customers</span><?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$jobs): ?>
  <div class="empty-state"><i class="bi bi-cup-hot"></i><h2 class="h5">No <?= $view === 'done' ? 'completed' : $view ?> jobs</h2><p><?= $view === 'today' ? 'New jobs appear here as soon as the office assigns them.' : 'Nothing to show.' ?></p></div>
<?php endif; ?>

<div class="job-list">
  <?php foreach ($jobs as $j):
      $late = !(int) $j['is_closed'] && $j['scheduled_date'] < date('Y-m-d');
      $link = url('technician/job?number=' . $j['booking_number']); ?>
    <article class="job-card<?= (int) $j['is_closed'] ? ' is-closed' : '' ?>">
      <a class="job-card-main" href="<?= e($link) ?>">
        <div class="job-time">
          <strong><?= e(explode(' – ', $j['scheduled_time'])[0] ?: $j['scheduled_time']) ?></strong>
          <small><?= $late ? '<span class="text-danger">' . e(date('d M', strtotime($j['scheduled_date']))) . '</span>' : e(date('D d M', strtotime($j['scheduled_date']))) ?></small>
        </div>
        <div class="job-body">
          <strong><?= e($j['service_name'] ?? 'Service') ?></strong>
          <span><?= e($j['customer_name']) ?></span>
          <small><i class="bi bi-geo-alt"></i> <?= e($j['area_name'] ?? $j['city'] ?? '') ?> · <?= e(mb_strimwidth($j['address'], 0, 40, '…')) ?></small>
          <div class="mt-1"><?= status_badge($j['status_label'], $j['status_color']) ?><?= $late ? ' <span class="prio prio-urgent">Overdue</span>' : '' ?></div>
        </div>
        <i class="bi bi-chevron-right job-chev"></i>
      </a>
      <?php if (!(int) $j['is_closed']): ?>
        <div class="job-actions">
          <a href="<?= e(tel_link('+91' . $j['phone'])) ?>"><i class="bi bi-telephone"></i> Call</a>
          <a href="<?= e(maps_link($j['address'], $j['area_name'], $j['city'])) ?>" target="_blank" rel="noopener"><i class="bi bi-map"></i> Map</a>
          <a href="<?= e($link) ?>"><i class="bi bi-arrow-right-circle"></i> Update</a>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<?php require ROOT_PATH . '/includes/technician/footer.php'; ?>
