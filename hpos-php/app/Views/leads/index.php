<?php /** @var list<array> $leads */
$kind = ['NEW' => 'warn', 'CONTACTED' => 'neutral', 'QUALIFIED' => 'ok', 'REJECTED' => 'fail']; ?>
<div class="page-head">
  <h1>Supplier leads</h1>
  <?php if (can('Suppliers', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/leads/new')) ?>">New lead</a><?php endif; ?>
</div>
<div class="card flush">
<table class="table">
  <thead><tr><th>Company</th><th>Contact</th><th>Material</th><th>Source</th><th>Status</th><th>Next follow-up</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($leads as $l): ?>
    <tr>
      <td><?= e($l['company_name']) ?></td>
      <td><?= e($l['contact_name']) ?></td>
      <td><?= e($l['material']) ?></td>
      <td><?= e($l['source']) ?></td>
      <td><?= pill($l['status'], $kind[$l['status']] ?? 'neutral') ?></td>
      <td><?= e(fmt_dt($l['next_follow_up'])) ?></td>
      <td class="actions"><?php if (can('Suppliers', 'update')): ?><a href="<?= e(url('/leads/' . $l['id'] . '/edit')) ?>">Edit</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$leads): ?><tr><td colspan="7" class="muted">No leads yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
