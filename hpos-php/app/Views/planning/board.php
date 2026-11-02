<?php /** @var list<array> $days @var list<array> $rows @var list<array> $unplanned @var string $from @var string $prev @var string $next @var string $thisWeek @var string $today */
$canPlan = can('WorkOrders', 'update');
$cls = static fn (array $j): string => $j['status'] === 'COMPLETED' ? 'plan-done' : ($j['late'] ? 'plan-late' : ($j['status'] === 'ACTIVE' ? 'plan-active' : 'plan-queued')); ?>
<div class="page-head">
  <h1>Planning board</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/planning?from=' . $prev)) ?>" aria-label="Previous week">&larr; Week</a>
    <a class="btn<?= $from === $thisWeek ? ' btn-quiet' : '' ?>" href="<?= e(url('/planning')) ?>">This week</a>
    <a class="btn" href="<?= e(url('/planning?from=' . $next)) ?>" aria-label="Next week">Week &rarr;</a>
    <a class="btn" href="<?= e(url('/work-orders')) ?>">Work orders</a>
  </div>
</div>
<p class="plan-legend"><span class="plan-key plan-queued">Planned</span> <span class="plan-key plan-active">Running</span> <span class="plan-key plan-done">Done</span> <span class="plan-key plan-late">Late</span> <span class="muted">Two weeks from <?= e(date('j M', strtotime($from))) ?>. Click a bar to change its dates or machine.</span></p>
<div class="card flush">
<table class="table plan-board">
  <thead>
    <tr><th class="plan-ws">Machine</th>
    <?php foreach ($days as $d): ?><th class="plan-day<?= $d['weekend'] ? ' plan-weekend' : '' ?><?= $d['today'] ? ' plan-today' : '' ?>"><?= e($d['dow']) ?><br><?= e($d['day']) ?></th><?php endforeach; ?>
    </tr>
  </thead>
  <?php foreach ($rows as $r): $ws = $r['ws']; ?>
  <tbody class="plan-machine">
    <?php foreach ($r['lanes'] as $li => $cells): ?>
    <tr>
      <?php if ($li === 0): ?><th scope="row" class="plan-ws" rowspan="<?= count($r['lanes']) ?>"><?= e($ws['code']) ?> <span class="muted"><?= e($ws['name']) ?></span><?= $ws['status'] === 'MAINTENANCE' ? '<br>' . pill('Maintenance', 'warn') : '' ?></th><?php endif; ?>
      <?php foreach ($cells as $c): ?>
        <?php if ($c[0] === 'gap'): ?>
          <td colspan="<?= (int) $c[1] ?>" class="plan-gap"></td>
        <?php else: $j = $c[2]; ?>
          <td colspan="<?= (int) $c[1] ?>" class="plan-bar <?= e($cls($j)) ?><?= $c[3] ? ' plan-cut-l' : '' ?><?= $c[4] ? ' plan-cut-r' : '' ?>">
            <a href="<?= e(url('/job-cards/' . $j['job_card_id'] . '/plan')) ?>" title="<?= e($j['work_order_number'] . ' · ' . $j['fg_name'] . ' · ' . fmt_num($j['planned_qty'], 0) . ' m · ' . substr((string) $j['planned_start'], 0, 10) . ' to ' . substr((string) $j['planned_end'], 0, 10)) ?>">
              <?= e($j['work_order_number']) ?> <span class="plan-sub"><?= e($j['fg_code']) ?> · <?= e(fmt_num($j['planned_qty'], 0)) ?> m</span>
            </a>
          </td>
        <?php endif; ?>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tbody><tr><td colspan="15" class="muted">No machines yet. Add them under Workstations.</td></tr></tbody><?php endif; ?>
</table>
</div>

<h2>Not planned yet</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Work order</th><th>Product</th><th>Machine</th><th class="num">Metres</th><th>Needed by</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($unplanned as $u): ?>
    <tr>
      <td><a href="<?= e(url('/work-orders/' . ($u['work_order_id'] ?? ''))) ?>"><?= e($u['work_order_number']) ?></a></td>
      <td><?= e($u['fg_code']) ?> <span class="muted"><?= e($u['fg_name']) ?></span></td>
      <td><?= e($u['workstation_code']) ?></td>
      <td class="num"><?= e(fmt_num($u['planned_qty'], 0)) ?></td>
      <td><?= e($u['delivery_date'] ? substr((string) $u['delivery_date'], 0, 10) : '-') ?></td>
      <td><?= status_pill($u['status']) ?></td>
      <td class="actions"><?php if ($canPlan): ?><a href="<?= e(url('/job-cards/' . $u['job_card_id'] . '/plan')) ?>">Plan</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$unplanned): ?><tr><td colspan="7" class="muted">Every queued and running job has a plan.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">A job is planned on the machine of its job card. To plan a new job, add a job card on its work order first. Planning is guidance for the floor; it does not stop anyone starting a job.</p>
