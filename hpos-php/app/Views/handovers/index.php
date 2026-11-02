<?php /** @var list<array> $rows @var ?string $status */
$shifts = App\Controllers\HandoverController::SHIFTS; ?>
<div class="page-head">
  <h1>Shift handovers</h1>
  <?php if (can('Production', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/handovers/new')) ?>">New handover</a><?php endif; ?>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/handovers')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <a href="<?= e(url('/handovers?status=PENDING')) ?>"<?= $status === 'PENDING' ? ' class="active" aria-current="page"' : '' ?>>Waiting to be accepted</a>
  <a href="<?= e(url('/handovers?status=ACCEPTED')) ?>"<?= $status === 'ACCEPTED' ? ' class="active" aria-current="page"' : '' ?>>Accepted</a>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>When</th><th>Shift</th><th>Machine</th><th>From</th><th>To</th><th class="num">Scrap kg</th><th>Notes</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(fmt_dt($r['shift_date'])) ?></td>
      <td><?= e($shifts[$r['shift_type']] ?? $r['shift_type']) ?></td>
      <td><?= e($r['workstation_code']) ?></td>
      <td><?= e($r['outgoing_name']) ?></td>
      <td><?= e($r['incoming_name']) ?></td>
      <td class="num"><?= e(fmt_num($r['scrap_generated_qty'], 1)) ?></td>
      <td><?= nl2br(e($r['notes'])) ?></td>
      <td><?= $r['status'] === 'ACCEPTED' ? pill('Accepted', 'ok') : pill('Waiting', 'warn') ?></td>
      <td class="actions"><?php if ($r['status'] === 'PENDING' && can('Production', 'create')): ?><?= action_form('/handovers/' . $r['id'] . '/accept', 'Accept', null, 'btn') ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">No handovers.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
