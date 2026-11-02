<?php /** @var array $row @var array<string,string> $orders @var array<string,string> $customers @var ?string $error */ ?>
<h1>New sales invoice</h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p class="muted">From a sales order, the invoice copies the order lines with the default GST rate. Without one, choose the customer and add lines afterwards.</p>
  <form method="post" action="<?= e(url('/invoices')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('order_id', 'Sales order', $row['order_id'] ?? '', $orders, ['blank' => 'No order (enter lines myself)']) ?>
    <?= select_field('customer_id', 'Customer (when there is no order)', $row['customer_id'] ?? '', $customers, ['blank' => 'Taken from the order']) ?>
    <div class="grid-2">
      <div><?= field('invoice_date', 'Invoice date', $row['invoice_date'] ?? '', ['type' => 'date', 'hint' => 'Defaults to today.']) ?></div>
      <div><?= field('due_date', 'Due date', $row['due_date'] ?? '', ['type' => 'date', 'hint' => 'Defaults to the credit days setting.']) ?></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Create draft</button>
      <a class="btn" href="<?= e(url('/invoices')) ?>">Cancel</a>
    </div>
  </form>
</section>
