<?php /** @var array $row @var list<array{name:string,value:string}> $rows @var array<string,string> $batches @var ?string $error */
$statuses = App\Controllers\QualityController::STATUSES;
unset($statuses['DRAFT']);
$line = static function (array $r): string {
    return '<div class="bom-line">'
        . '<div>' . field('param[]', 'Measurement', $r['name'], ['maxlength' => 60]) . '</div>'
        . '<div>' . field('value[]', 'Reading', $r['value'], ['maxlength' => 80]) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
}; ?>
<h1>New inspection</h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/quality')) ?>">
    <?= App\Core\Csrf::field() ?>
    <div class="grid-2">
      <div><?= select_field('batch_number', 'Batch', $row['batch_number'] ?? '', $batches, ['blank' => 'Choose...', 'required' => true, 'empty' => ['/stock/adjust', 'Add opening stock']]) ?></div>
      <div><?= select_field('status', 'Result', $row['status'] ?? 'PASS', $statuses) ?></div>
      <div><?= field('sample_size', 'Sample size', $row['sample_size'] ?? '5', ['type' => 'number', 'step' => '1', 'min' => '1']) ?></div>
    </div>
    <?= textarea_field('rework_notes', 'Notes', $row['rework_notes'] ?? '', ['rows' => 2]) ?>
    <p class="hint">Required for rework or reject. A scrap result writes the whole batch off.</p>

    <h2>Measurements</h2>
    <?php if (!empty($row['batch_number']) && count($rows) > 0 && $rows[0]['name'] !== ''): ?><p class="muted">Pre-filled from the item's QC template.</p><?php endif; ?>
    <div id="qc-lines"><?php foreach ($rows as $r) { echo $line($r); } ?></div>
    <template id="qc-line-template"><?= $line(['name' => '', 'value' => '']) ?></template>
    <p><button class="btn" type="button" data-add-row="qc-line-template" data-target="qc-lines">Add measurement</button></p>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save inspection</button>
      <a class="btn" href="<?= e(url('/quality')) ?>">Cancel</a>
    </div>
  </form>
</section>
