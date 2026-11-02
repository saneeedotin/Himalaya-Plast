<?php /** @var array $row @var list<array> $lines @var array<string,string> $suppliers @var array<string,string> $items @var ?string $error */
$line = static function (array $l, array $items): string {
    return '<div class="invoice-line">'
        . '<div>' . select_field('item_id[]', 'Item', $l['item_id'], $items, ['blank' => 'Choose...']) . '</div>'
        . '<div>' . field('qty[]', 'Quantity', $l['qty'], ['type' => 'number', 'step' => '0.001', 'min' => '0']) . '</div>'
        . '<div>' . field('rate[]', 'Rate', $l['rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<div>' . field('tax_rate[]', 'GST %', $l['tax_rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<h1>New supplier bill</h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/purchase-invoices')) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="grid-2">
      <div><?= select_field('supplier_id', 'Supplier', $row['supplier_id'] ?? '', $suppliers, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/suppliers/new', 'Add a supplier']]) ?></div>
      <div></div>
      <div><?= field('invoice_date', 'Bill date', $row['invoice_date'] ?? '', ['type' => 'date', 'hint' => 'Defaults to today.']) ?></div>
      <div><?= field('due_date', 'Due date', $row['due_date'] ?? '', ['type' => 'date', 'hint' => 'Defaults to the credit days setting.']) ?></div>
    </div>
    <h2>Lines</h2>
    <p class="muted">Enter the lines from the bill so GST is split properly. If you only have the total, leave the lines empty and fill in the amount below.</p>
    <div id="invoice-lines"><?php foreach ($lines as $l) { echo $line($l, $items); } ?></div>
    <template id="invoice-line-template"><?= $line(['item_id' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18'], $items) ?></template>
    <p><button class="btn" type="button" data-add-row="invoice-line-template" data-target="invoice-lines">Add line</button></p>
    <?= field('total_amount', 'Total amount (only when there are no lines)', $row['total_amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save draft</button>
      <a class="btn" href="<?= e(url('/purchase-invoices')) ?>">Cancel</a>
    </div>
  </form>
</section>
