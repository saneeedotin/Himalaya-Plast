<?php /** @var ?array $item @var array $row @var ?string $error */
$isNew = $item === null;
$qc = $row['qc_template'] ?? '';
if (is_array($qc)) { $qc = json_encode($qc, JSON_PRETTY_PRINT); } ?>
<h1><?= $isNew ? 'New item' : 'Edit item' ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/items' : '/items/' . $item['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <?= field('code', 'Code', $row['code'] ?? '', ['required' => true, 'maxlength' => 60]) ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 255]) ?>
    <?= select_field('category', 'Category', $row['category'] ?? 'RAW_MATERIAL', App\Controllers\ItemController::CATEGORIES) ?>
    <?= field('uom', 'Unit', $row['uom'] ?? '', ['required' => true, 'maxlength' => 20, 'hint' => 'For example Kg, Meter, Nos.']) ?>
    <?= field('hsn_code', 'HSN code', $row['hsn_code'] ?? '', ['maxlength' => 12]) ?>
    <?= field('standard_cost', 'Standard cost', $row['standard_cost'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
    <?= field('min_stock_level', 'Minimum stock', $row['min_stock_level'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'hint' => 'A reorder alert shows when available stock falls below this.']) ?>
    <?= textarea_field('qc_template', 'QC template (JSON)', $qc, ['rows' => 6]) ?>
    <p class="hint">Optional. The list of checks the quality screen shows for this item.</p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url($isNew ? '/items' : '/items/' . $item['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
