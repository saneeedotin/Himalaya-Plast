<?php /** @var array $row @var list<array> $lines @var ?array $note @var string $kind @var array<string,string> $parties @var list<array> $invoices @var ?string $error */
$kinds = App\Controllers\CreditNoteController::KINDS;
[$label, , $partyLabel] = $kinds[$kind];
$line = static function (array $l): string {
    return '<div class="note-line">'
        . '<div>' . field('description[]', 'Description', $l['description'], ['maxlength' => 255]) . '</div>'
        . '<div>' . field('hsn[]', 'HSN', $l['hsn'], ['maxlength' => 20]) . '</div>'
        . '<div>' . field('qty[]', 'Quantity', $l['qty'], ['type' => 'number', 'step' => '0.001', 'min' => '0']) . '</div>'
        . '<div>' . field('rate[]', 'Rate', $l['rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0']) . '</div>'
        . '<div>' . field('tax_rate[]', 'GST %', $l['tax_rate'], ['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100']) . '</div>'
        . '<button class="btn btn-quiet" type="button" data-remove-row aria-label="Remove line">Remove</button>'
        . '</div>';
};
$action = $note ? '/credit-notes/' . $note['id'] : '/credit-notes'; ?>
<h1><?= e($title) ?></h1>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($action)) ?>">
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="grid-2">
      <div>
        <label for="f_cn_party"><?= e($partyLabel) ?></label>
        <select id="f_cn_party" name="party_id" required>
          <option value="">Choose...</option>
          <?php foreach ($parties as $id => $name): ?><option value="<?= e($id) ?>"<?= ($row['party_id'] ?? '') === $id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="f_cn_invoice">Against <?= $kind === 'SALES' ? 'invoice' : 'bill' ?> (optional)</label>
        <select id="f_cn_invoice" name="invoice_id">
          <option value="">Not tied to one <?= $kind === 'SALES' ? 'invoice' : 'bill' ?></option>
          <?php foreach ($invoices as $i): ?><option value="<?= e($i['id']) ?>" data-party="<?= e($i['party']) ?>"<?= ($row['invoice_id'] ?? '') === $i['id'] ? ' selected' : '' ?>><?= e($i['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><?= field('note_date', 'Date', $row['note_date'] ?? date('Y-m-d'), ['type' => 'date', 'required' => true]) ?></div>
      <div><?= field('reason', 'Reason', $row['reason'] ?? '', ['maxlength' => 255, 'hint' => 'Goods returned, rate difference, discount after billing...']) ?></div>
    </div>
    <h2>Lines</h2>
    <div id="note-lines">
      <?php foreach ($lines as $l) { echo $line($l); } ?>
    </div>
    <template id="note-line-template"><?= $line(['description' => '', 'hsn' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18']) ?></template>
    <p><button class="btn" type="button" data-add-row="note-line-template" data-target="note-lines">Add line</button></p>
    <p class="muted">Tax follows the <?= e(strtolower($partyLabel)) ?>'s GSTIN: CGST and SGST within your state, IGST outside it. The total cannot be more than the <?= $kind === 'SALES' ? 'invoice' : 'bill' ?> it is against. This adjusts money only; it does not move stock.</p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save draft</button>
      <a class="btn" href="<?= e(url($note ? '/credit-notes/' . $note['id'] : '/credit-notes')) ?>">Cancel</a>
    </div>
  </form>
</section>
