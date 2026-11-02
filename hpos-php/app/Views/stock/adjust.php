<?php /** @var array $row @var array<string,string> $items @var array<string,string> $reasons @var ?string $error */ ?>
<div class="page-head">
  <h1>Adjust stock</h1>
  <div class="head-actions"><a class="btn" href="<?= e(url('/stock')) ?>">Back to stock</a></div>
</div>
<section class="card card-narrow">
  <p class="muted">Use this to enter opening stock, correct a count, or write off damaged or lost stock. Every change is kept in the stock ledger.</p>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/stock/adjust')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('direction', 'What are you doing?', $row['direction'] ?? 'IN', ['IN' => 'Add stock (opening stock, found stock)', 'OUT' => 'Remove stock (damage, loss, count too high)'], ['required' => true]) ?>
    <?= select_field('item_id', 'Item', $row['item_id'] ?? '', $items, ['required' => true, 'blank' => 'Choose...', 'empty' => ['/items/new', 'Add an item first']]) ?>
    <?= field('qty', 'Quantity', $row['qty'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0.001', 'required' => true]) ?>
    <?= select_field('reason', 'Reason', $row['reason'] ?? 'OPENING', $reasons, ['required' => true]) ?>
    <?= textarea_field('notes', 'Note', $row['notes'] ?? '', ['maxlength' => 400, 'hint' => 'Required when the reason is Other.']) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save adjustment</button>
      <a class="btn" href="<?= e(url('/stock')) ?>">Cancel</a>
    </div>
  </form>
</section>
