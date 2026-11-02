<?php /** @var list<array> $dies */ ?>
<div class="page-head">
  <h1>Dies</h1>
  <?php if (can('Workstations', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/dies/new')) ?>">New die</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Code</th><th>Name</th><th>Status</th><th class="num">Hours run</th><th class="num">Service at (hours)</th><th></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($dies as $d): ?>
    <tr>
      <td><?= e($d['code']) ?></td>
      <td><?= e($d['name']) ?></td>
      <td><?= e($d['status']) ?></td>
      <td class="num"><?= e(fmt_num($d['total_running_hours'])) ?></td>
      <td class="num"><?= e(fmt_num($d['maintenance_threshold_hours'])) ?></td>
      <td><?= (int) $d['maintenance_due'] === 1 ? pill('Service due', 'warn') : '' ?></td>
      <td class="actions">
        <?php if (can('Workstations', 'update')): ?><a href="<?= e(url('/dies/' . $d['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
        <?php if (can('Workstations', 'delete')): ?><?= action_form('/dies/' . $d['id'] . '/delete', 'Delete', 'Delete this die?', 'link') ?><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$dies): ?><tr><td colspan="7" class="muted">No dies yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p><a href="<?= e(url('/workstations')) ?>">Workstations</a></p>
