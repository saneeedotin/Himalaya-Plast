<?php /** @var array $row @var array<string,string> $orders @var array<string,string> $batches @var list<array> $lines @var ?string $error */
$line = static function (array $l, array $batches): string {
    return '<div class="order-line">'
        . '<div>' . select_field('batch_id[]', 'Finished-goods batch', $l['batch_id'], $batches, ['blank' => 'Choose...']) . '</div>'
        . '<div>' . field('carton_count[]', 'Cartons', $l['carton_count'], ['type' => 'number', 'step' => '1', 'min' => '1', 'max' => '2000']) . '</div>'
        . '<div>' . field('qty_per_carton[]', 'Metres per carton', $l['qty_per_carton'], ['type' => 'number', 'step' => '0.001', 'min' => '0']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<h1>Pack an order</h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p class="muted">Creates a delivery note and one serial-numbered carton label per carton. Nothing leaves stock until each carton is scanned at the gate.</p>
  <form method="post" action="<?= e(url('/dispatch')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('order_id', 'Sales order', $row['order_id'] ?? '', $orders, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/orders/new', 'Create an order']]) ?>
    <h2>Batches</h2>
    <div id="pack-lines"><?php foreach ($lines as $l) { echo $line($l, $batches); } ?></div>
    <template id="pack-line-template"><?= $line(['batch_id' => '', 'carton_count' => '', 'qty_per_carton' => ''], $batches) ?></template>
    <p><button class="btn" type="button" data-add-row="pack-line-template" data-target="pack-lines">Add batch</button></p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Create delivery note</button>
      <a class="btn" href="<?= e(url('/dispatch')) ?>">Cancel</a>
    </div>
  </form>
</section>
