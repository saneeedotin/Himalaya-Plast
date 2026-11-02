<?php /** @var string $from @var string $to @var string $tab @var array<string,string> $tabs @var list<array> $rows @var ?array $s */
$keep = ['tab' => $tab];
$sum = static fn (string $k): float => array_sum(array_map(static fn (array $r): float => (float) $r[$k], $rows)); ?>
<div class="page-head"><h1>GST</h1><a class="btn" href="<?= e(url('/books/reports')) ?>">Reports</a></div>
<nav class="tabs" aria-label="GST report">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(url('/books/reports/gst?' . http_build_query(['tab' => $k, 'from' => $from, 'to' => $to]))) ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?= period_form('/books/reports/gst', ['from' => $from, 'to' => $to, 'keep' => $keep]) ?>

<?php if ($tab === 'summary'): $s = $s ?? []; $heads = ['CGST' => 'cgst', 'SGST' => 'sgst', 'IGST' => 'igst'];
    $outTotal = $inTotal = 0.0; ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Tax</th><th class="num">Charged on sales</th><th class="num">Paid on purchases</th><th class="num">To pay</th></tr></thead>
  <tbody>
  <?php foreach ($heads as $label => $k): $out = (float) ($s["out_$k"] ?? 0); $in = (float) ($s["in_$k"] ?? 0); $outTotal += $out; $inTotal += $in; ?>
    <tr><td><?= e($label) ?></td><td class="num"><?= e(fmt_num($out)) ?></td><td class="num"><?= e(fmt_num($in)) ?></td><td class="num"><?= e(fmt_num($out - $in)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="subtotal total"><td>Total</td><td class="num"><?= e(fmt_num($outTotal)) ?></td><td class="num"><?= e(fmt_num($inTotal)) ?></td><td class="num"><?= e(fmt_num($outTotal - $inTotal)) ?></td></tr></tfoot>
</table>
</div>
<div class="card">
  <dl class="facts">
    <div><dt>Taxable sales</dt><dd><?= e(fmt_num($s['out_taxable'] ?? 0)) ?></dd></div>
    <div><dt>Taxable purchases</dt><dd><?= e(fmt_num($s['in_taxable'] ?? 0)) ?></dd></div>
    <div><dt><?= $outTotal - $inTotal >= 0 ? 'Tax to pay' : 'Tax credit carried forward' ?></dt><dd><strong><?= e(fmt_num(abs($outTotal - $inTotal))) ?></strong></dd></div>
  </dl>
</div>
<p class="muted">Sales and purchases are net of credit and debit notes. A negative "to pay" is input credit you can carry forward. This is a working summary to prepare your return, not a filed return.</p>

<?php elseif ($tab === 'hsn'): ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>HSN</th><th>Unit</th><th class="num">Quantity</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th><th class="num">Total tax</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['hsn']) ?></td><td><?= e($r['uom']) ?></td><td class="num"><?= e(fmt_num($r['qty'], 3)) ?></td><td class="num"><?= e(fmt_num($r['taxable'])) ?></td><td class="num"><?= e(fmt_num($r['cgst'])) ?></td><td class="num"><?= e(fmt_num($r['sgst'])) ?></td><td class="num"><?= e(fmt_num($r['igst'])) ?></td><td class="num"><?= e(fmt_num($r['total_tax'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No sales in this period.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($rows): ?><tfoot><tr class="subtotal"><td colspan="3">Total</td><td class="num"><?= e(fmt_num($sum('taxable'))) ?></td><td class="num"><?= e(fmt_num($sum('cgst'))) ?></td><td class="num"><?= e(fmt_num($sum('sgst'))) ?></td><td class="num"><?= e(fmt_num($sum('igst'))) ?></td><td class="num"><?= e(fmt_num($sum('total_tax'))) ?></td></tr></tfoot><?php endif; ?>
</table>
</div>
<p class="muted">Sales invoice lines less credit-note lines, grouped by the item's HSN code. Items without an HSN code are listed as "(none)": add the code on the item.</p>

<?php else: $base = $tab === 'sales' ? '/invoices/' : '/purchase-invoices/'; ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Date</th><th>Document</th><th><?= $tab === 'sales' ? 'Customer' : 'Supplier' ?></th><th>GSTIN</th><th class="num">Taxable value</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(substr((string) $r['doc_date'], 0, 10)) ?></td>
      <td><a href="<?= e(url(in_array($r['doc_type'], ['INVOICE', 'BILL'], true) ? $base . $r['id'] : '/credit-notes/' . $r['id'])) ?>"><?= e($r['doc_no']) ?></a> <span class="muted"><?= e(ucfirst(strtolower($r['doc_type']))) ?></span></td>
      <td><?= e($r['party']) ?></td>
      <td><?= e($r['gstin'] ?: 'Unregistered') ?></td>
      <td class="num"><?= e(fmt_num($r['taxable'])) ?></td><td class="num"><?= e(fmt_num($r['cgst'])) ?></td><td class="num"><?= e(fmt_num($r['sgst'])) ?></td><td class="num"><?= e(fmt_num($r['igst'])) ?></td><td class="num"><?= e(fmt_num($r['total'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">Nothing in this period.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($rows): ?><tfoot><tr class="subtotal"><td colspan="4">Total</td><td class="num"><?= e(fmt_num($sum('taxable'))) ?></td><td class="num"><?= e(fmt_num($sum('cgst'))) ?></td><td class="num"><?= e(fmt_num($sum('sgst'))) ?></td><td class="num"><?= e(fmt_num($sum('igst'))) ?></td><td class="num"><?= e(fmt_num($sum('total'))) ?></td></tr></tfoot><?php endif; ?>
</table>
</div>
<p class="muted">Only posted documents. Credit and debit notes appear as negative lines.</p>
<?php endif; ?>
