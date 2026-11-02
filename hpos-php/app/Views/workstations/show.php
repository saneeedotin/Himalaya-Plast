<?php /** @var array $ws @var list<array> $jobs @var list<array> $downtime */ ?>
<div class="page-head">
  <h1><?= e($ws['name']) ?> <small><?= e($ws['code']) ?></small></h1>
  <div class="head-actions">
    <?php if (can('Workstations', 'update')): ?><a class="btn" href="<?= e(url('/workstations/' . $ws['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <?php if (can('Workstations', 'delete')): ?><?= action_form('/workstations/' . $ws['id'] . '/delete', 'Delete', 'Delete this workstation?', 'btn btn-danger') ?><?php endif; ?>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Status</dt><dd><?= e($ws['status']) ?></dd></div>
    <div><dt>Hourly rate</dt><dd><?= e(fmt_num($ws['hourly_rate'])) ?></dd></div>
  </dl>
</section>

<h2>Recent job cards</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Work order</th><th>Item</th><th>Status</th><th>Operator</th><th>Die</th><th>Started</th><th class="num">Good</th><th class="num">Scrap</th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j): ?>
    <tr>
      <td><?= e($j['work_order_number']) ?></td>
      <td><?= e($j['fg_code']) ?> <?= e($j['fg_name']) ?></td>
      <td><?= e($j['status']) ?></td>
      <td><?= e($j['operator_name']) ?></td>
      <td><?= e($j['die_code']) ?></td>
      <td><?= e(fmt_dt($j['started_at'])) ?></td>
      <td class="num"><?= e(fmt_num($j['good_qty'], 3)) ?></td>
      <td class="num"><?= e(fmt_num($j['scrap_qty'], 3)) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$jobs): ?><tr><td colspan="8" class="muted">No job cards yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Recent downtime</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Started</th><th>Ended</th><th>Reason</th><th>Logged by</th><th>Notes</th></tr></thead>
  <tbody>
  <?php foreach ($downtime as $d): ?>
    <tr><td><?= e(fmt_dt($d['start_time'])) ?></td><td><?= e(fmt_dt($d['end_time'])) ?: pill('Open', 'fail') ?></td><td><?= e($d['reason_code']) ?></td><td><?= e($d['logged_by']) ?></td><td><?= e($d['notes']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$downtime): ?><tr><td colspan="5" class="muted">No downtime logged.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
