<?php /** @var list<array> $orders */ ?>
<div class="page-head"><h1>Accounting exports</h1></div>
<p class="muted">Download one order at a time. The Book Keeper CSV has the sales voucher lines or the raw material used. The Tally file is an XML sales voucher to import into TallyPrime (taxable value; add the GST ledgers in Tally).</p>
<div class="card flush">
<table class="table">
  <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Download</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="<?= e(url('/orders/' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
      <td><?= e($o['customer_name']) ?></td>
      <td><?= status_pill($o['status']) ?></td>
      <td class="num"><?= e(fmt_num($o['total_amount'])) ?></td>
      <td class="actions">
        <a href="<?= e(url('/exports/bookkeeper?order=' . $o['id'] . '&type=SALES_INVOICE')) ?>">Sales CSV</a> &middot;
        <a href="<?= e(url('/exports/bookkeeper?order=' . $o['id'] . '&type=MATERIAL_CONSUMPTION')) ?>">Material CSV</a> &middot;
        <a href="<?= e(url('/exports/tally?order=' . $o['id'])) ?>">Tally XML</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="5" class="muted">No confirmed orders yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
