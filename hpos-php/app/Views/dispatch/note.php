<?php /** @var array $dn @var list<array> $cartons */
$byBatch = [];
foreach ($cartons as $c) {
    $k = $c['batch_number'];
    $byBatch[$k] ??= ['item' => $c['item_name'], 'cartons' => 0, 'qty' => 0.0];
    $byBatch[$k]['cartons']++;
    $byBatch[$k]['qty'] += (float) $c['quantity'];
} ?>
<div class="no-print"><p><button class="btn btn-primary" type="button" data-print>Print</button> <a href="<?= e(url('/dispatch/' . $dn['id'])) ?>">Back</a></p></div>
<article class="paper">
  <h1>Delivery note <?= e($dn['dn_number']) ?></h1>
  <dl class="facts">
    <div><dt>Customer</dt><dd><?= e($dn['customer_name']) ?></dd></div>
    <div><dt>Order</dt><dd><?= e($dn['order_number']) ?></dd></div>
    <div><dt>Date</dt><dd><?= e(substr((string) ($dn['dispatched_at'] ?: $dn['created_at']), 0, 10)) ?></dd></div>
    <div><dt>Transporter</dt><dd><?= e($dn['transporter_name'] ?: '') ?></dd></div>
    <div><dt>Vehicle</dt><dd><?= e($dn['vehicle_number'] ?: '') ?></dd></div>
    <div><dt>LR number</dt><dd><?= e($dn['lr_number'] ?: '') ?></dd></div>
  </dl>
  <table class="table">
    <thead><tr><th>Item</th><th>Batch</th><th class="num">Cartons</th><th class="num">Metres</th></tr></thead>
    <tbody>
    <?php foreach ($byBatch as $batch => $b): ?>
      <tr><td><?= e($b['item']) ?></td><td><?= e($batch) ?></td><td class="num"><?= (int) $b['cartons'] ?></td><td class="num"><?= e(fmt_num($b['qty'], 0)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2">Total</td><td class="num"><?= count($cartons) ?></td><td class="num"><?= e(fmt_num(array_sum(array_column($byBatch, 'qty')), 0)) ?></td></tr></tfoot>
  </table>
  <div class="signatures"><span>Dispatched by</span><span>Received by</span></div>
</article>
