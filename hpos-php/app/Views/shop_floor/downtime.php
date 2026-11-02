<?php /** @var list<array> $rows @var int $days @var array<string,string> $reasons */ ?>
<div class="page-head">
  <h1>Downtime</h1>
  <a class="btn" href="<?= e(url('/shop-floor')) ?>">Shop floor</a>
</div>
<nav class="tabs" aria-label="Period">
  <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label): ?>
    <a href="<?= e(url('/downtime?days=' . $d)) ?>"<?= $days === $d ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php $total = array_sum(array_map(static fn (array $r): int => (int) $r['duration_mins'], $rows)); ?>
<p class="muted"><?= count($rows) ?> stops, <?= (int) $total ?> minutes in closed stops.</p>
<div class="card flush">
<table class="table">
  <thead><tr><th>Machine</th><th>Reason</th><th>Started</th><th>Ended</th><th class="num">Minutes</th><th>Logged by</th><th>Notes</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['workstation_code']) ?></td>
      <td><?= e($reasons[$r['reason_code']] ?? $r['reason_code']) ?></td>
      <td><?= e(fmt_dt($r['start_time'])) ?></td>
      <td><?= $r['end_time'] ? e(fmt_dt($r['end_time'])) : pill('Open', 'fail') ?></td>
      <td class="num"><?= $r['duration_mins'] !== null ? (int) $r['duration_mins'] : '' ?></td>
      <td><?= e($r['logged_by']) ?></td>
      <td><?= e($r['notes']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No stops in this period.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
