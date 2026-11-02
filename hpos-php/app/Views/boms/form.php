<?php /** @var ?array $bom @var array $row @var list<array> $lines @var array $fgItems @var array $rmItems @var ?string $error */
$isNew = $bom === null;
$fgOptions = [];
foreach ($fgItems as $id => $i) { $fgOptions[$id] = $i['code'] . ' ' . $i['name']; }
$rmOptions = [];
foreach ($rmItems as $id => $i) { $rmOptions[$id] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')'; }
$line = static function (array $l, array $rmOptions): string {
    return '<div class="bom-line">'
        . '<div>' . select_field('rm_item_id[]', 'Raw material', $l['rm_item_id'], $rmOptions, ['blank' => 'Choose...']) . '</div>'
        . '<div>' . field('qty_per_unit[]', 'Quantity', $l['qty_per_unit'], ['type' => 'number', 'step' => '0.0001', 'min' => '0']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<h1><?= $isNew ? 'New BOM' : 'Edit BOM' ?></h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($isNew ? '/boms' : '/boms/' . $bom['id'])) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="grid-2">
      <div><?= field('name', 'BOM name', $row['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?></div>
      <div><?= select_field('fg_item_id', 'Finished good', $row['fg_item_id'] ?? '', $fgOptions, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/items/new', 'Add a finished-goods item']]) ?></div>
      <div><?= field('output_qty', 'Output quantity', $row['output_qty'] ?? '', ['type' => 'number', 'step' => '0.001', 'min' => '0', 'required' => true, 'hint' => 'The quantities below produce this much finished good.']) ?></div>
      <div><?= field('scrap_percent', 'Scrap (%)', $row['scrap_percent'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '99.99', 'required' => true, 'hint' => 'Share of the material that is lost, for example 2.5 for 2.5%.']) ?></div>
    </div>

    <h2>Raw materials</h2>
    <div id="bom-lines">
      <?php foreach ($lines as $l) { echo $line($l, $rmOptions); } ?>
    </div>
    <template id="bom-line-template"><?= $line(['rm_item_id' => '', 'qty_per_unit' => ''], $rmOptions) ?></template>
    <p><button class="btn" type="button" data-add-row="bom-line-template" data-target="bom-lines">Add material</button></p>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn" href="<?= e(url($isNew ? '/boms' : '/boms/' . $bom['id'])) ?>">Cancel</a>
    </div>
  </form>
</section>
