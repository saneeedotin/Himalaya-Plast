<?php /** @var list<array> $rows @var array<string,array> $buckets */
$labels = App\Controllers\LedgerController::BUCKETS;
$total = array_sum(array_map(static fn (array $r): float => (float) $r['outstanding'], $rows)); ?>
<div class="page-head">
  <h1>Receivables</h1>
  <a class="btn" href="<?= e(url('/invoices')) ?>">Sales invoices</a>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Total outstanding</dt><dd><strong><?= e(fmt_num($total)) ?></strong></dd></div>
    <?php foreach ($labels as $k => $label): ?>
      <div><dt><?= e($label) ?></dt><dd><?= e(fmt_num($buckets[$k]['outstanding'] ?? 0)) ?> <span class="muted">(<?= (int) ($buckets[$k]['invoices'] ?? 0) ?>)</span></dd></div>
    <?php endforeach; ?>
  </dl>
</section>
<div class="card flush">
<table class="table">
  <thead><tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Ageing</th><th class="num">Days late</th><th class="num">Total</th><th class="num">Outstanding</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('/invoices/' . $r['id'])) ?>"><?= e($r['invoice_number']) ?></a></td>
      <td><?= e($r['customer_name']) ?></td>
      <td><?= e(substr((string) $r['due_date'], 0, 10)) ?></td>
      <td><?= pill($labels[$r['aging_bucket']] ?? $r['aging_bucket'], $r['aging_bucket'] === 'NOT_DUE' ? 'neutral' : ($r['aging_bucket'] === '1-30' ? 'warn' : 'fail')) ?></td>
      <td class="num"><?= (int) $r['days_overdue'] ?></td>
      <td class="num"><?= e(fmt_num($r['total_amount'])) ?></td>
      <td class="num"><?= e(fmt_num($r['outstanding'])) ?></td>
      <td class="actions"><?php if (can('Accounts', 'create')): ?><a href="<?= e(url('/payments/new?sales_invoice=' . $r['id'])) ?>">Record payment</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Nothing is owed to you.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
