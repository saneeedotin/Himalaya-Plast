<?php /** @var array $dn @var list<array> $cartons */ ?>
<div class="no-print"><p><button class="btn btn-primary" type="button" data-print>Print</button> <a href="<?= e(url('/dispatch/' . $dn['id'])) ?>">Back</a></p></div>
<div class="labels">
<?php foreach ($cartons as $c): ?>
  <div class="label">
    <div class="label-head"><strong><?= e($dn['customer_name']) ?></strong><span><?= e($dn['dn_number']) ?></span></div>
    <div class="label-body"><?= e($c['item_name']) ?><br><?= e(fmt_num($c['quantity'], 0)) ?> m &middot; Batch <?= e($c['batch_number']) ?></div>
    <?= App\Core\Barcode::svg($c['carton_code'], 50, 2) ?>
  </div>
<?php endforeach; ?>
</div>
