<?php /** @var list<array> $fields @var list<array> $labels @var array<string,string> $kinds */
$canEdit = can('QC', 'update'); ?>
<div class="page-head">
  <h1>QC sheet setup</h1>
  <div class="head-actions">
    <a class="btn" href="<?= e(url('/quality/sheets')) ?>">QC sheets</a>
    <a class="btn" href="<?= e(url('/quality/machines')) ?>">QC machines</a>
  </div>
</div>
<p class="muted">This is the client's own QC sheet. Choose what is measured, name it the way the factory does, and set the words on the sheet. The sheet, its print and the CSV follow what you set here. Limits for each product are set on the product's page.</p>

<h2>What is measured</h2>
<?php foreach ($fields as $f): ?>
<section class="card setup-field<?= (int) $f['is_active'] === 0 ? ' setup-off' : '' ?>">
  <form method="post" action="<?= e(url('/quality/setup/fields')) ?>" class="setup-grid">
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="id" value="<?= e($f['id']) ?>">
    <input type="hidden" name="kind" value="<?= e($f['kind']) ?>">
    <div><label>Code</label><input type="text" value="<?= e($f['code']) ?>" readonly aria-label="Code"></div>
    <div><?= field('label', 'Name on the sheet', $f['label'], ['maxlength' => 60, 'required' => true, 'readonly' => !$canEdit]) ?></div>
    <div><label>Kind</label><input type="text" value="<?= e(ucfirst(strtolower($f['kind']))) ?>" readonly aria-label="Kind"></div>
    <?php if ($f['kind'] === 'NUMBER'): ?>
      <div><?= field('unit', 'Unit', $f['unit'] ?? '', ['maxlength' => 12, 'readonly' => !$canEdit]) ?></div>
      <div><label class="check"><input type="checkbox" name="has_limits" value="1"<?= (int) $f['has_limits'] === 1 ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> Has limits per product</label></div>
    <?php elseif ($f['kind'] === 'CHOICE'): ?>
      <div><?= field('choices', 'Choices (VALUE=Name;...)', $f['choices'] ?? '', ['maxlength' => 255, 'readonly' => !$canEdit]) ?></div>
      <div><?= field('bad_values', 'Counts as a fault', $f['bad_values'] ?? '', ['maxlength' => 255, 'readonly' => !$canEdit, 'hint' => 'For example NOT_OK. Flags the reading.']) ?></div>
    <?php else: ?>
      <div></div><div></div>
    <?php endif; ?>
    <div><?= field('sort_no', 'Order', (string) $f['sort_no'], ['type' => 'number', 'step' => '1', 'readonly' => !$canEdit]) ?></div>
    <div><label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $f['is_active'] === 1 ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> Shown on the sheet</label>
      <?php if ((int) $f['used_count'] > 0): ?><p class="hint"><?= (int) $f['used_count'] ?> readings use it.</p><?php endif; ?></div>
    <?php if ($canEdit): ?><div><button class="btn" type="submit">Save</button></div><?php endif; ?>
  </form>
</section>
<?php endforeach; ?>

<?php if ($canEdit): ?>
<h2>Add something to measure</h2>
<section class="card">
  <form method="post" action="<?= e(url('/quality/setup/fields')) ?>" class="setup-grid">
    <?= App\Core\Csrf::field() ?>
    <div><?= field('code', 'Code', '', ['maxlength' => 30, 'required' => true, 'hint' => 'Capital letters, digits and _, for example THICKNESS. It cannot change later.']) ?></div>
    <div><?= field('label', 'Name on the sheet', '', ['maxlength' => 60, 'required' => true]) ?></div>
    <div><?= select_field('kind', 'Kind', 'NUMBER', $kinds) ?></div>
    <div><?= field('unit', 'Unit (numbers)', '', ['maxlength' => 12]) ?></div>
    <div><?= field('choices', 'Choices (a choice only)', '', ['maxlength' => 255, 'hint' => 'For example OK=OK;NOT_OK=Not OK']) ?></div>
    <div><?= field('bad_values', 'Counts as a fault (a choice only)', '', ['maxlength' => 255, 'hint' => 'For example NOT_OK']) ?></div>
    <div><?= field('sort_no', 'Order', '10', ['type' => 'number', 'step' => '1']) ?></div>
    <div><label class="check"><input type="checkbox" name="has_limits" value="1" checked> Has limits per product (numbers)</label><label class="check"><input type="checkbox" name="is_active" value="1" checked> Shown on the sheet</label></div>
    <div><button class="btn btn-primary" type="submit">Add field</button></div>
  </form>
</section>
<?php endif; ?>

<h2>Words on the sheet</h2>
<section class="card">
  <form method="post" action="<?= e(url('/quality/setup/labels')) ?>">
    <?= App\Core\Csrf::field() ?>
    <table class="table label-table">
      <thead><tr><th>Used for</th><th>Shown as</th><th>Show</th></tr></thead>
      <tbody>
      <?php $names = ['DIE_NO' => 'Die', 'MFG_DATE' => 'Manufacturing date', 'BATCH_NO' => 'Batch', 'DTD' => 'DTD', 'M_NO' => 'Machine number', 'PRODUCT_CODE' => 'Product code', 'COLOUR' => 'Colour', 'METRES' => 'Metres',
          'X1' => 'Spare header field 1', 'X2' => 'Spare header field 2', 'X3' => 'Spare header field 3', 'SR_NO' => 'Serial number column', 'DATE_TIME' => 'Date and time column', 'AUTHORISED' => 'Signature column', 'FOOTER' => 'Line at the foot of the print'];
      foreach ($labels as $l): $fixed = in_array($l['label_key'], ['SR_NO', 'DATE_TIME', 'AUTHORISED', 'FOOTER'], true); ?>
        <tr>
          <td><?= e($names[$l['label_key']] ?? $l['label_key']) ?></td>
          <td><input name="label[<?= e($l['label_key']) ?>]" type="text" maxlength="60" value="<?= e($l['label']) ?>" aria-label="Label for <?= e($names[$l['label_key']] ?? $l['label_key']) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td><input type="checkbox" name="visible[<?= e($l['label_key']) ?>]" value="1"<?= (int) $l['is_visible'] === 1 ? ' checked' : '' ?><?= $fixed || !$canEdit ? ' disabled' : '' ?> aria-label="Show <?= e($names[$l['label_key']] ?? $l['label_key']) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canEdit): ?><button class="btn btn-primary" type="submit">Save labels</button><?php endif; ?>
    <p class="hint">The spare header fields appear on a new sheet when switched on, for anything else the client wants at the top (for example an operator name).</p>
  </form>
</section>
