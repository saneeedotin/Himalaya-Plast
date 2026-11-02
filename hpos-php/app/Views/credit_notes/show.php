<?php /** @var array $n @var list<array> $lines */
$id = $n['id'];
$kinds = App\Controllers\CreditNoteController::KINDS;
$label = $kinds[$n['kind']][0];
$draft = $n['status'] === 'DRAFT';
$posted = $n['status'] === 'POSTED';
$inter = (float) $n['igst_amount'] > 0;
$invoicePath = $n['kind'] === 'SALES' ? '/invoices/' . $n['sales_invoice_id'] : '/purchase-invoices/' . $n['purchase_invoice_id']; ?>
<div class="page-head">
  <h1><?= e($n['note_number']) ?> <?= status_pill($n['status']) ?></h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url("/credit-notes/$id/print")) ?>" target="_blank" rel="noopener">Print</a>
    <?php if ($draft && can('Accounts', 'update')): ?>
      <a class="btn" href="<?= e(url("/credit-notes/$id/edit")) ?>">Edit</a>
      <?= action_form("/credit-notes/$id/post", 'Post ' . strtolower($label), 'Post this ' . strtolower($label) . '? It will change the books and the invoice balance, and can no longer be edited.', 'btn btn-primary') ?>
    <?php endif; ?>
    <?php if ($draft && can('Accounts', 'delete')): ?><?= action_form("/credit-notes/$id/delete", 'Delete draft', 'Delete this draft?', 'btn btn-danger') ?><?php endif; ?>
    <?php if ($posted && can('Accounts', 'delete')): ?><?= action_form("/credit-notes/$id/cancel", 'Cancel ' . strtolower($label), 'Cancel this ' . strtolower($label) . '? A reversing entry is posted and the invoice balance is restored.', 'btn btn-danger') ?><?php endif; ?>
    <a class="btn" href="<?= e(url('/credit-notes')) ?>">All notes</a>
  </div>
</div>
<section class="card">
  <dl class="facts">
    <div><dt>Type</dt><dd><?= e($label) ?></dd></div>
    <div><dt><?= $n['kind'] === 'SALES' ? 'Customer' : 'Supplier' ?></dt><dd><a href="<?= e(url(($n['kind'] === 'SALES' ? '/customers/' . $n['customer_id'] : '/suppliers/' . $n['supplier_id']))) ?>"><?= e($n['party_name']) ?></a></dd></div>
    <div><dt>GSTIN</dt><dd><?= e($n['party_gstin'] ?: 'Unregistered') ?></dd></div>
    <div><dt>Against</dt><dd><?php if ($n['invoice_number']): ?><a href="<?= e(url($invoicePath)) ?>"><?= e($n['invoice_number']) ?></a><?php else: ?>-<?php endif; ?></dd></div>
    <div><dt>Date</dt><dd><?= e(substr((string) $n['note_date'], 0, 10)) ?></dd></div>
    <div><dt>Reason</dt><dd><?= e($n['reason'] ?: '-') ?></dd></div>
  </dl>
</section>
<h2>Lines</h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Description</th><th>HSN</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST %</th><th class="num">GST</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['description']) ?></td><td><?= e($l['hsn_code']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td><td class="num"><?= e(trim_zeros($l['tax_rate'])) ?></td><td class="num"><?= e(fmt_num($l['tax_amount'])) ?></td><td class="num"><?= e(fmt_num($l['total_amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<section class="card totals">
  <dl class="facts">
    <div><dt>Subtotal</dt><dd><?= e(fmt_num($n['subtotal_amount'])) ?></dd></div>
    <?php if ($inter): ?>
      <div><dt>IGST</dt><dd><?= e(fmt_num($n['igst_amount'])) ?></dd></div>
    <?php else: ?>
      <div><dt>CGST</dt><dd><?= e(fmt_num($n['cgst_amount'])) ?></dd></div>
      <div><dt>SGST</dt><dd><?= e(fmt_num($n['sgst_amount'])) ?></dd></div>
    <?php endif; ?>
    <div><dt>Total</dt><dd><strong><?= e(fmt_num($n['total_amount'])) ?></strong></dd></div>
  </dl>
  <p class="muted"><?= e(amount_in_words($n['total_amount'])) ?></p>
</section>
<?php if ($draft): ?><p class="muted">A draft changes nothing yet. Post it to update the books and the invoice balance.</p><?php endif; ?>
<?php if ($posted): ?><p class="muted">Posted. This note reduced what <?= $n['kind'] === 'SALES' ? 'the customer owes you' : 'you owe the supplier' ?> and is in the GST registers as a negative line. It does not change stock.</p><?php endif; ?>
