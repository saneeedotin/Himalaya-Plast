<?php /** @var list<array> $sheets @var ?string $status @var string $machine */
$q = static fn (array $extra): string => '/quality/sheets' . (($keep = array_filter($extra + ['machine' => $machine])) ? '?' . http_build_query($keep) : ''); ?>
<div class="page-head">
  <h1>QC sheets</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/quality/machines')) ?>">By machine</a>
    <?php if (can('QC', 'update')): ?><a class="btn" href="<?= e(url('/quality/setup')) ?>">Sheet setup</a><?php endif; ?>
    <a class="btn" href="<?= e(url('/quality')) ?>">Inspections</a>
    <?php if (can('QC', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/quality/sheets/new')) ?>"><?= icon('plus') ?>Start a sheet</a><?php endif; ?>
  </div>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url($q([]))) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <a href="<?= e(url($q(['status' => 'OPEN']))) ?>"<?= $status === 'OPEN' ? ' class="active" aria-current="page"' : '' ?>>Open</a>
  <a href="<?= e(url($q(['status' => 'CLOSED']))) ?>"<?= $status === 'CLOSED' ? ' class="active" aria-current="page"' : '' ?>>Closed</a>
  <?php if ($machine !== ''): ?><a href="<?= e(url('/quality/sheets')) ?>">Show every machine</a><?php endif; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Sheet</th><th>Machine</th><th>Batch</th><th>Product</th><th>Die</th><th>Made</th><th>Status</th><th class="num">Readings</th><th class="num">Out of range</th></tr></thead>
  <tbody>
  <?php foreach ($sheets as $s): ?>
    <tr>
      <td><a href="<?= e(url('/quality/sheets/' . $s['id'])) ?>"><?= e($s['sheet_number']) ?></a></td>
      <td><?= e($s['machine_code'] ?: '-') ?></td>
      <td><?= e($s['batch_number']) ?></td>
      <td><?= e($s['item_code']) ?> <span class="muted"><?= e($s['item_name']) ?></span></td>
      <td><?= e($s['die_code'] ?: '-') ?></td>
      <td><?= e(substr((string) $s['mfg_date'], 0, 10)) ?></td>
      <td><?= pill($s['status'] === 'OPEN' ? 'Open' : 'Closed', $s['status'] === 'OPEN' ? 'warn' : 'ok') ?></td>
      <td class="num"><?= (int) $s['readings'] ?></td>
      <td class="num"><?= (int) $s['out_of_range'] > 0 ? pill((string) (int) $s['out_of_range'], 'fail') : '0' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$sheets): ?><tr><td colspan="9" class="muted">No QC sheets yet. Start one on a running job; add a reading about every half hour.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
