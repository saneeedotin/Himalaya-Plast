<?php /** @var array $n @var list<array> $lines @var array<string,string> $settings */
$company = $settings['company_name'] ?? 'Himalaya Plast';
$gstin = $settings['company_gstin'] ?? '';
$inter = (float) $n['igst_amount'] > 0;
$sales = $n['kind'] === 'SALES';
$title = $n['status'] === 'DRAFT' ? 'DRAFT ' : '';
$title .= $sales ? 'CREDIT NOTE' : 'DEBIT NOTE'; ?>
<div class="no-print"><p><button class="btn btn-primary" type="button" data-print>Print or save as PDF</button> <a href="<?= e(url('/credit-notes/' . $n['id'])) ?>">Back</a></p></div>
<article class="paper">
  <header class="paper-head">
    <div><h1><?= e($company) ?></h1><?php if ($gstin): ?><p>GSTIN: <?= e($gstin) ?></p><?php endif; ?></div>
    <div class="paper-title"><strong><?= e($title) ?></strong><br><?= e($n['note_number']) ?></div>
  </header>
  <dl class="facts">
    <div><dt><?= $sales ? 'Issued to' : 'Issued to supplier' ?></dt><dd><?= e($n['party_name']) ?><br><?= e($n['party_gstin'] ? 'GSTIN: ' . $n['party_gstin'] : 'Unregistered') ?></dd></div>
    <div><dt>Date</dt><dd><?= e(substr((string) $n['note_date'], 0, 10)) ?></dd></div>
    <?php if ($n['invoice_number']): ?><div><dt>Against</dt><dd><?= e($n['invoice_number']) ?></dd></div><?php endif; ?>
    <?php if ($n['reason']): ?><div><dt>Reason</dt><dd><?= e($n['reason']) ?></dd></div><?php endif; ?>
  </dl>
  <table class="table">
    <thead><tr><th>#</th><th>Description</th><th>HSN</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST %</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr><td><?= $i + 1 ?></td><td><?= e($l['description']) ?></td><td><?= e($l['hsn_code']) ?></td><td class="num"><?= e(fmt_num($l['qty'], 3)) ?></td><td class="num"><?= e(fmt_num($l['rate'])) ?></td><td class="num"><?= e(fmt_num($l['amount'])) ?></td><td class="num"><?= e(trim_zeros($l['tax_rate'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5" class="num">Subtotal</td><td class="num"><?= e(fmt_num($n['subtotal_amount'])) ?></td><td></td></tr>
      <?php if ($inter): ?>
        <tr><td colspan="5" class="num">IGST</td><td class="num"><?= e(fmt_num($n['igst_amount'])) ?></td><td></td></tr>
      <?php else: ?>
        <tr><td colspan="5" class="num">CGST</td><td class="num"><?= e(fmt_num($n['cgst_amount'])) ?></td><td></td></tr>
        <tr><td colspan="5" class="num">SGST</td><td class="num"><?= e(fmt_num($n['sgst_amount'])) ?></td><td></td></tr>
      <?php endif; ?>
      <tr><td colspan="5" class="num">Total</td><td class="num"><?= e(fmt_num($n['total_amount'])) ?></td><td></td></tr>
    </tfoot>
  </table>
  <p><strong>Amount in words:</strong> <?= e(amount_in_words($n['total_amount'])) ?></p>
  <div class="signatures"><span><?= $sales ? 'Customer signature' : 'Supplier signature' ?></span><span>For <?= e($company) ?></span></div>
</article>
