<?php /** @var ?array $order @var array $row @var list<array> $lines @var array<string,string> $customers @var array<string,string> $items @var ?string $error */
$isNew = $order === null;
$line = static function (array $l, array $items): string {
    return '<div class="order-line">'
        . '<div>' . select_field('item_id[]', 'Item', $l['item_id'], $items, ['blank' => 'Choose...']) . '</div>'
        . '<div>' . field('qty[]', 'Quantity', $l['qty'], ['type' => 'number', 'step' => '0.001', 'min' => '0']) . '</div>'
        . '<div>' . field('rate[]', 'Rate', $l['rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<h1><?= $isNew ? 'New order' : 'Edit ' . e($order['order_number']) ?></h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/orders' : '/orders/' . $order['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="grid-2">
      <div><?= select_field('customer_id', 'Customer', $row['customer_id'] ?? '', $customers, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/customers/new', 'Add a customer']]) ?></div>
      <div><?= field('delivery_date', 'Delivery date', $row['delivery_date'] ?? '', ['type' => 'date', 'required' => true]) ?></div>
      <div><?= select_field('approval_method', 'Approved by', $row['approval_method'] ?? 'WHATSAPP', App\Controllers\OrderController::APPROVAL) ?></div>
    </div>
    <?= textarea_field('notes', 'Notes', $row['notes'] ?? '', ['rows' => 2]) ?>

    <h2>Items</h2>
    <div id="order-lines">
      <?php foreach ($lines as $l) { echo $line($l, $items); } ?>
    </div>
    <template id="order-line-template"><?= $line(['item_id' => '', 'qty' => '', 'rate' => ''], $items) ?></template>
    <p><button class="btn" type="button" data-add-row="order-line-template" data-target="order-lines">Add item</button></p>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save order</button>
      <a class="btn" href="<?= e(url($isNew ? '/orders' : '/orders/' . $order['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
