<?php /** @var array $row @var array<string,string> $items */ ?>
<h1>Receive material</h1>
<section class="card card-narrow">
  <p class="muted">Raw material that has arrived without a purchase order, or that you are adding to stock. For material you ordered, use Goods receipts. Each entry becomes one lot you can trace through production.</p>
  <form method="post" action="<?= e(url('/stock/receive')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('item_code', 'Raw material', $row['item_code'] ?? '', $items, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/items/new', 'Add a raw-material item']]) ?>
    <?= field('bags', 'Number of bags', '1', ['type' => 'number', 'step' => '1', 'min' => '1', 'max' => '1000', 'required' => true]) ?>
    <?= field('qty', 'Weight of one bag', '25', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true, 'hint' => 'In the item\'s unit, usually kg.']) ?>
    <?= field('lot', 'Lot or bill number (optional)', '', ['maxlength' => 60, 'autocomplete' => 'off', 'hint' => 'The supplier\'s lot or invoice number, to find it later. Leave empty and HPOS numbers the lot for you.']) ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Receive</button>
  </form>
</section>
