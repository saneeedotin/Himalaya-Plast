<?php /** @var array $inv @var list<array> $lines @var list<array> $payments @var array<string,string> $items */
$id = $inv['id'];
$draft = $inv['status'] === 'DRAFT';
$open = in_array($inv['status'], ['UNPAID', 'PARTIAL'], true);
$canUpdate = can('Accounts', 'update');
$line = static function (array $l, array $items): string {
    return '<div class="invoice-line">'
        . '<div>' . select_field('item_id[]', 'Item', $l['item_id'], $items, ['blank' => 'Choose...']) . '</div>'
        . '<div>' . field('qty[]', 'Quantity', $l['qty'], ['type' => 'number', 'step' => '0.001', 'min' => '0']) . '</div>'
        . '<div>' . field('rate[]', 'Rate', $l['rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<div>' . field('tax_rate[]', 'GST %', $l['tax_rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<div class="page-head">
  <h1><?= e($inv['invoice_number']) ?> <?= status_pill($inv['status']) ?></h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url("/invoices/$id/print")) ?>" target="_blank" rel="noopener">Print invoice</a>
    <?php if ($open && can('Accounts', 'create')): ?><a class="btn btn-primary" href="<?= e(url('/payments/new?sales_invoice=' . $id)) ?>">Record payment</a><?php endif; ?>
    <?php if (in_array($inv['status'], ['UNPAID', 'PARTIAL', 'PAID'], true) && can('Accounts', 'create')): ?><a class="btn" href="<?= e(url('/credit-notes/new?kind=SALES&invoice=' . $id)) ?>">Credit note</a><?php endif; ?>
    <?php if (in_array($inv['status'], ['DRAFT', 'UNPAID'], true) && can('Accounts', 'delete')): ?><?= action_form("/invoices/$id/cancel", 'Cancel invoice', 'Cancel this invoice?', 'btn btn-danger') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/invoices')) ?>">All invoices</a>
  </div>
</div>

<section class="card">
  <dl class="facts">
    <div><dt>Customer</dt><dd><a href="<?= e(url('/customers/' . $inv['customer_id'])) ?>"><?= e($inv['customer_name']) ?></a></dd></div>
    <div><dt>GSTIN</dt><dd><?= e($inv['customer_gstin'] ?: 'Unregistered') ?></dd></div>
    <div><dt>Order</dt><dd><?php if ($inv['sales_order_id']): ?><a href="<?= e(url('/orders/' . $inv['sales_order_id'])) ?>"><?= e($inv['order_number']) ?></a><?php else: ?>-<?php endif; ?></dd></div>
    <div><dt>Invoice date</dt><dd><?= e(substr((string) $inv['invoice_date'], 0, 10)) ?></dd></div>
    <div><dt>Due</dt><dd><?= e(substr((string) $inv['due_date'], 0, 10)) ?></dd></div>
    <div><dt>IRN</dt><dd><?= e($inv['irn'] ?: '-') ?></dd></div>
    <div><dt>E-way bill</dt><dd><?= e($inv['eway_bill_number'] ?: '-') ?></dd></div>
  </dl>
</section>

<?php if ($draft && $canUpdate): ?>
<h2>Lines</h2>
<section class="card">
  <form method="post" action="<?= e(url("/invoices/$id/lines")) ?>">
    <?= App\Core\Csrf::field() ?>
    <div id="invoice-lines">
      <?php foreach ($lines ?: [['item_id' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '']] as $l) {
          echo $line(['item_id' => $l['item_id'], 'qty' => trim_zeros($l['qty']), 'rate' => trim_zeros($l['rate']), 'tax_rate' => trim_zeros($l['tax_rate'] ?? '')], $items);
      } ?>
    </div>
    <template id="invoice-line-template"><?= $line(['item_id' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18'], $items) ?></template>
    <p><button class="btn" type="button" data-add-row="invoice-line-template" data-target="invoice-lines">Add line</button></p>
    <button class="btn btn-primary" type="submit">Save lines</button>
  </form>
</section>
<?php else: ?>
<h2>Lines</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Item</th><th>HSN</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST %</th><th class="num">GST</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> <?= e($l['name']) ?></td><td><?= e($l['hsn_code']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?> <?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td><td class="num"><?= e(trim_zeros($l['tax_rate'])) ?></td><td class="num"><?= e(fmt_num($l['tax_amount'])) ?></td><td class="num"><?= e(fmt_num($l['total_amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<section class="card totals">
  <dl class="facts">
    <div><dt>Subtotal</dt><dd><?= e(fmt_num($inv['subtotal_amount'])) ?></dd></div>
    <?php if ((float) $inv['igst_amount'] > 0): ?>
      <div><dt>IGST</dt><dd><?= e(fmt_num($inv['igst_amount'])) ?></dd></div>
    <?php else: ?>
      <div><dt>CGST</dt><dd><?= e(fmt_num($inv['cgst_amount'])) ?></dd></div>
      <div><dt>SGST</dt><dd><?= e(fmt_num($inv['sgst_amount'])) ?></dd></div>
    <?php endif; ?>
    <div><dt>Total</dt><dd><strong><?= e(fmt_num($inv['total_amount'])) ?></strong></dd></div>
    <div><dt>Outstanding</dt><dd><strong><?= e(fmt_num($inv['outstanding'])) ?></strong></dd></div>
  </dl>
  <p class="muted"><?= e(amount_in_words($inv['total_amount'])) ?></p>
</section>

<?php if ($draft && $canUpdate): ?>
<section class="card">
  <h3>Post this invoice</h3>
  <p class="muted">Posting locks the lines. The IRN and e-way bill number must come from the GST portal; leave them empty to add later.</p>
  <form method="post" action="<?= e(url("/invoices/$id/submit")) ?>" class="inline-form" data-confirm="Post this invoice? The lines can no longer be changed.">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('irn', 'IRN (optional)', '', ['maxlength' => 100]) ?></div>
    <div><?= field('eway', 'E-way bill (optional)', '', ['maxlength' => 20]) ?></div>
    <button class="btn btn-primary" type="submit">Post invoice</button>
  </form>
</section>
<?php elseif ($canUpdate && $inv['status'] !== 'CANCELLED'): ?>
<section class="card">
  <h3>E-invoice details</h3>
  <form method="post" action="<?= e(url("/invoices/$id/einvoice")) ?>" class="inline-form">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('irn', 'IRN', $inv['irn'] ?? '', ['maxlength' => 100]) ?></div>
    <div><?= field('eway', 'E-way bill', $inv['eway_bill_number'] ?? '', ['maxlength' => 20]) ?></div>
    <div><?= field('qr', 'QR code text', '', ['hint' => 'Leave empty to keep the current one.']) ?></div>
    <button class="btn" type="submit">Save</button>
  </form>
</section>
<?php endif; ?>

<?php if ($payments): ?>
<h2>Payments</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Payment</th><th>Date</th><th>Mode</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr><td><?= e($p['payment_number']) ?></td><td><?= e(substr((string) $p['payment_date'], 0, 10)) ?></td><td><?= e(App\Controllers\PaymentController::MODES[$p['mode_of_payment']] ?? $p['mode_of_payment']) ?></td><td><?= e($p['reference_number']) ?></td><td><?= status_pill($p['status']) ?></td><td class="num"><?= e(fmt_num($p['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
