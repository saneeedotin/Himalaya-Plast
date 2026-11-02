<?php /** @var array $report @var array $data */ ?>
<div class="page-head">
  <h1><?= e($report['report_number']) ?> <?= status_pill($report['status']) ?></h1>
  <a class="btn" href="<?= e(url('/quality')) ?>">All inspections</a>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Batch</dt><dd><?= e($report['batch_number']) ?></dd></div>
    <div><dt>Sample size</dt><dd><?= (int) $report['sample_size'] ?></dd></div>
    <div><dt>Inspector</dt><dd><?= e($report['inspector_name']) ?></dd></div>
    <div><dt>When</dt><dd><?= e(fmt_dt($report['inspected_at'])) ?></dd></div>
  </dl>
  <?php if ($report['rework_notes']): ?><p class="notes"><?= nl2br(e($report['rework_notes'])) ?></p><?php endif; ?>
</section>
<h2>Measurements</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Measurement</th><th>Reading</th></tr></thead>
  <tbody>
  <?php foreach ($data as $k => $v): ?>
    <tr><td><?= e($k) ?></td><td><?= e(is_scalar($v) ? $v : json_encode($v)) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$data): ?><tr><td colspan="2" class="muted">No measurements recorded.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
