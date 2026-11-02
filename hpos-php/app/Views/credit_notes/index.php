<?php /** @var ?string $kind @var list<array> $rows */
$kinds = App\Controllers\CreditNoteController::KINDS; ?>
<div class="page-head">
  <h1>Credit and debit notes</h1>
  <div class="head-actions">
    <?php if (can('Accounts', 'create')): ?>
      <a class="btn btn-primary" href="<?= e(url('/credit-notes/new?kind=SALES')) ?>"><?= icon('plus') ?>Credit note</a>
      <a class="btn" href="<?= e(url('/credit-notes/new?kind=PURCHASE')) ?>"><?= icon('plus') ?>Debit note</a>
    <?php endif; ?>
  </div>
</div>
<nav class="tabs" aria-label="Kind">
  <a href="<?= e(url('/credit-notes')) ?>"<?= $kind === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($kinds as $k => [, $plural]): ?>
    <a href="<?= e(url('/credit-notes?kind=' . $k)) ?>"<?= $kind === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($plural) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Note</th><th>Party</th><th>Invoice</th><th>Date</th><th>Reason</th><th>Status</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $n): ?>
    <tr>
      <td><a href="<?= e(url('/credit-notes/' . $n['id'])) ?>"><?= e($n['note_number']) ?></a> <span class="muted"><?= e($kinds[$n['kind']][0]) ?></span></td>
      <td><?= e($n['party_name']) ?></td>
      <td><?= e($n['invoice_number'] ?: '-') ?></td>
      <td><?= e(substr((string) $n['note_date'], 0, 10)) ?></td>
      <td><?= e($n['reason']) ?></td>
      <td><?= status_pill($n['status']) ?></td>
      <td class="num"><?= e(fmt_num($n['total_amount'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No notes yet. A credit note reduces what a customer owes (a return or a discount after billing); a debit note reduces what you owe a supplier.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
