<?php /** @var list<array> $machines */
$canStart = can('QC', 'create');
$due = count(array_filter($machines, static fn (array $m): bool => (int) $m['due'] === 1));
$ago = static function (?string $when): string {
    if (!$when) {
        return '-';
    }
    $min = (int) floor((time() - strtotime($when)) / 60);
    return $min < 1 ? 'just now' : ($min < 90 ? $min . ' min ago' : (int) floor($min / 60) . ' h ago');
}; ?>
<div class="page-head">
  <h1>QC machines</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/quality/sheets')) ?>">QC sheets</a>
    <?php if (can('QC', 'update')): ?><a class="btn" href="<?= e(url('/quality/setup')) ?>">Sheet setup</a><?php endif; ?>
  </div>
</div>
<?php if ($due > 0): ?><div class="alert alert-error" role="status"><?= $due ?> running machine<?= $due === 1 ? ' is' : 's are' ?> due a QC reading.</div><?php endif; ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Machine</th><th>Running job</th><th>QC sheet</th><th class="num">Readings</th><th>Last reading</th><th>Next reading</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($machines as $m): ?>
    <tr>
      <td><strong><?= e($m['code']) ?></strong> <span class="muted"><?= e($m['name']) ?></span><?= $m['status'] === 'MAINTENANCE' ? ' ' . pill('Maintenance', 'warn') : '' ?></td>
      <td><?= $m['job_card_id'] ? e($m['batch_number'] ?: 'no batch yet') : '<span class="muted">Not running</span>' ?></td>
      <td><?= $m['sheet_id'] ? '<a href="' . e(url('/quality/sheets/' . $m['sheet_id'])) . '">' . e($m['sheet_number']) . '</a>' : '<span class="muted">No open sheet</span>' ?></td>
      <td class="num"><?= $m['sheet_id'] ? (int) $m['readings'] : '-' ?></td>
      <td><?= e($ago($m['last_reading'] ?: $m['sheet_started'])) ?></td>
      <td><?= (int) $m['due'] === 1 ? pill('Due now', 'fail') : ($m['job_card_id'] ? pill('On time', 'ok') : '') ?></td>
      <td class="actions">
        <?php if ($m['sheet_id']): ?><a href="<?= e(url('/quality/sheets/' . $m['sheet_id'])) ?>">Add reading</a>
        <?php elseif ($canStart && $m['job_card_id'] && $m['batch_number']): ?><a href="<?= e(url('/quality/sheets/new?job_card=' . $m['job_card_id'])) ?>">Start sheet</a><?php endif; ?>
        <a href="<?= e(url('/quality/sheets?machine=' . $m['workstation_id'])) ?>">Sheets</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$machines): ?><tr><td colspan="7" class="muted">No machines yet. Add them under Workstations.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">Each machine gets its own QC sheet. A running machine is flagged when no reading has been taken for longer than one and a half times the usual gap (30 minutes by default, set in Settings).</p>
