<?php /** @var list<array> $reports @var ?string $status */
$statuses = App\Controllers\QualityController::STATUSES; ?>
<div class="page-head">
  <h1>Quality</h1>
  <?php if (can('QC', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/quality/new')) ?>">New inspection</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Result">
  <a href="<?= e(url('/quality')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $k => $label): ?>
    <a href="<?= e(url('/quality?status=' . $k)) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Report</th><th>Batch</th><th>Result</th><th class="num">Sample</th><th>Inspector</th><th>When</th></tr></thead>
  <tbody>
  <?php foreach ($reports as $r): ?>
    <tr>
      <td><a href="<?= e(url('/quality/' . $r['id'])) ?>"><?= e($r['report_number']) ?></a></td>
      <td><?= e($r['batch_number']) ?></td>
      <td><?= status_pill($r['status']) ?></td>
      <td class="num"><?= (int) $r['sample_size'] ?></td>
      <td><?= e($r['inspector_name']) ?></td>
      <td><?= e(fmt_dt($r['inspected_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$reports): ?><tr><td colspan="6" class="muted">No inspections<?= $status ? ' with this result' : ' yet' ?>.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
