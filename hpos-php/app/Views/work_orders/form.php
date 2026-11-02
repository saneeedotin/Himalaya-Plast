<?php /** @var array $row @var array<string,string> $items @var array<string,string> $orders @var ?string $error */ ?>
<h1>New work order</h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/work-orders')) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= select_field('sales_order_id', 'Sales order', $row['sales_order_id'] ?? '', $orders, ['blank' => 'None (stock production)']) ?>
    <?= select_field('fg_item_id', 'Item to make', $row['fg_item_id'] ?? '', $items, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/items/new', 'Add a finished-goods item']]) ?>
    <?= field('planned_qty', 'Planned quantity', $row['planned_qty'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true]) ?>
    <?= field('fg_batch_number', 'Batch number', $row['fg_batch_number'] ?? '', ['maxlength' => 80, 'hint' => 'Optional. Left empty, a batch number is assigned when production is first logged.']) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Create</button>
      <a class="btn" href="<?= e(url('/work-orders')) ?>">Cancel</a>
    </div>
  </form>
</section>
