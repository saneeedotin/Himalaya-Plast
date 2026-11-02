<?php /** @var list<array> $invoices @var ?string $status */
$statuses = App\Controllers\InvoiceController::STATUSES; ?>
<div class="page-head">
  <h1>Sales invoices</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/receivables')) ?>">Receivables</a>
    <?php if (can('Accounts', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/invoices/new')) ?>">New invoice</a><?php endif; ?>
  </div>
</div>
<nav class="tabs" aria-label="Status">
  <a href="<?= e(url('/invoices')) ?>"<?= $status === null ? ' class="active" aria-current="page"' : '' ?>>All</a>
  <?php foreach ($statuses as $k => $label): ?>
    <a href="<?= e(url('/invoices?status=' . $k)) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="card flush">
<table class="table">
  <thead><tr><th>Invoice</th><th>Customer</th><th>Order</th><th>Date</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Outstanding</th></tr></thead>
  <tbody>
  <?php foreach ($invoices as $i): ?>
    <tr>
      <td><a href="<?= e(url('/invoices/' . $i['id'])) ?>"><?= e($i['invoice_number']) ?></a></td>
      <td><?= e($i['customer_name']) ?></td>
      <td><?= e($i['order_number']) ?></td>
      <td><?= e(substr((string) $i['invoice_date'], 0, 10)) ?></td>
      <td><?= e(substr((string) $i['due_date'], 0, 10)) ?></td>
      <td><?= status_pill($i['status']) ?></td>
      <td class="num"><?= e(fmt_num($i['total_amount'])) ?></td>
      <td class="num"><?= e(fmt_num($i['outstanding'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$invoices): ?><tr><td colspan="8" class="muted">No invoices.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
