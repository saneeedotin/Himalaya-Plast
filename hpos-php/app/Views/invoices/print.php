<?php /** @var array $inv @var list<array> $lines @var array<string,string> $settings */
$company = $settings['company_name'] ?? 'Himalaya Plast';
$gstin = $settings['company_gstin'] ?? '';
$inter = (float) $inv['igst_amount'] > 0; ?>
<div class="no-print"><p><button class="btn btn-primary" type="button" data-print>Print or save as PDF</button> <a href="<?= e(url('/invoices/' . $inv['id'])) ?>">Back</a></p></div>
<article class="paper">
  <header class="paper-head">
    <div><h1><?= e($company) ?></h1><?php if ($gstin): ?><p>GSTIN: <?= e($gstin) ?></p><?php else: ?><p class="muted no-print">Set the company GSTIN in Settings before issuing invoices.</p><?php endif; ?></div>
    <div class="paper-title"><strong><?= $inv['status'] === 'DRAFT' ? 'DRAFT INVOICE' : 'TAX INVOICE' ?></strong><br><?= e($inv['invoice_number']) ?></div>
  </header>
  <dl class="facts">
    <div><dt>Bill to</dt><dd><?= e($inv['customer_name']) ?><br><?= e($inv['customer_gstin'] ? 'GSTIN: ' . $inv['customer_gstin'] : 'Unregistered') ?></dd></div>
    <div><dt>Invoice date</dt><dd><?= e(substr((string) $inv['invoice_date'], 0, 10)) ?></dd></div>
    <div><dt>Due date</dt><dd><?= e(substr((string) $inv['due_date'], 0, 10)) ?></dd></div>
    <?php if ($inv['order_number']): ?><div><dt>Order</dt><dd><?= e($inv['order_number']) ?></dd></div><?php endif; ?>
    <?php if ($inv['dn_number']): ?><div><dt>Delivery note</dt><dd><?= e($inv['dn_number']) ?></dd></div><?php endif; ?>
    <?php if ($inv['irn']): ?><div><dt>IRN</dt><dd><?= e($inv['irn']) ?></dd></div><?php endif; ?>
    <?php if ($inv['eway_bill_number']): ?><div><dt>E-way bill</dt><dd><?= e($inv['eway_bill_number']) ?></dd></div><?php endif; ?>
  </dl>
  <table class="table">
    <thead><tr><th>#</th><th>Description</th><th>HSN</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST %</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $n => $l): ?>
      <tr><td><?= $n + 1 ?></td><td><?= e($l['name']) ?></td><td><?= e($l['hsn_code']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?> <?= e($l['uom']) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td><td class="num"><?= e(trim_zeros($l['tax_rate'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5" class="num">Subtotal</td><td class="num"><?= e(fmt_num($inv['subtotal_amount'])) ?></td><td></td></tr>
      <?php if ($inter): ?>
        <tr><td colspan="5" class="num">IGST</td><td class="num"><?= e(fmt_num($inv['igst_amount'])) ?></td><td></td></tr>
      <?php else: ?>
        <tr><td colspan="5" class="num">CGST</td><td class="num"><?= e(fmt_num($inv['cgst_amount'])) ?></td><td></td></tr>
        <tr><td colspan="5" class="num">SGST</td><td class="num"><?= e(fmt_num($inv['sgst_amount'])) ?></td><td></td></tr>
      <?php endif; ?>
      <tr><td colspan="5" class="num">Total</td><td class="num"><?= e(fmt_num($inv['total_amount'])) ?></td><td></td></tr>
    </tfoot>
  </table>
  <p><strong>Amount in words:</strong> <?= e(amount_in_words($inv['total_amount'])) ?></p>
  <div class="signatures"><span>Customer signature</span><span>For <?= e($company) ?></span></div>
</article>
