<?php /** @var list<array> $rows */
$total = array_sum(array_map(static fn (array $r): float => (float) $r['outstanding'], $rows)); ?>
<div class="page-head">
  <h1>Payables</h1>
  <a class="btn" href="<?= e(url('/purchase-invoices')) ?>">Purchase invoices</a>
</div>
<section class="card"><dl class="facts"><div><dt>Total you owe</dt><dd><strong><?= e(fmt_num($total)) ?></strong></dd></div></dl></section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Bill</th><th>Supplier</th><th>Due</th><th class="num">Days late</th><th class="num">Total</th><th class="num">Outstanding</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/purchase-invoices/' . $r['id'])) ?>"><?= e($r['invoice_number']) ?></a></td>
      <td><?= e($r['supplier_name']) ?></td>
      <td><?= e(substr((string) $r['due_date'], 0, 10)) ?></td>
      <td class="num"><?= (int) $r['days_overdue'] > 0 ? pill((string) (int) $r['days_overdue'], 'fail') : '0' ?></td>
      <td class="num"><?= e(fmt_num($r['total_amount'])) ?></td>
      <td class="num"><?= e(fmt_num($r['outstanding'])) ?></td>
      <td class="actions"><?php if (can('Accounts', 'create')): ?><a href="<?= e(url('/payments/new?purchase_invoice=' . $r['id'])) ?>">Record payment</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">You owe nothing.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
