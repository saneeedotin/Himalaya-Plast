<?php /** @var list<array> $workstations */
$kind = ['RUNNING' => 'ok', 'IDLE' => 'neutral', 'MAINTENANCE' => 'warn']; ?>
<div class="page-head">
  <h1>Workstations</h1>
  <?php if (can('Workstations', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/workstations/new')) ?>">New workstation</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Code</th><th>Name</th><th>Status</th><th class="num">Hourly rate</th><th class="num">Stops (30 days)</th><th class="num">Downtime (min)</th></tr></thead>
  <tbody>
  <?php foreach ($workstations as $w): ?>
    <tr>
      <td><a href="<?= e(url('/workstations/' . $w['id'])) ?>"><?= e($w['code']) ?></a></td>
      <td><?= e($w['name']) ?></td>
      <td><?= pill($w['status'], $kind[$w['status']] ?? 'neutral') ?><?= (int) $w['open_stops'] > 0 ? ' ' . pill('Stopped now', 'fail') : '' ?></td>
      <td class="num"><?= e(fmt_num($w['hourly_rate'])) ?></td>
      <td class="num"><?= (int) $w['stops_30d'] ?></td>
      <td class="num"><?= (int) $w['downtime_mins_30d'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$workstations): ?><tr><td colspan="6" class="muted">No workstations yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p><a href="<?= e(url('/dies')) ?>">Dies</a></p>
