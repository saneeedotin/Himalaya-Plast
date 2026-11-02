<?php /** @var ?array $account @var array $row @var ?string $error */
$system = $account && (int) $account['is_system'] === 1;
$current = ($row['acct_type'] ?? '') . ':' . ($row['subtype'] ?? '');
$action = $account ? '/books/accounts/' . $account['id'] : '/books/accounts'; ?>
<h1><?= e($title) ?></h1>
<section class="card card-narrow">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url($action)) ?>">
    <?= App\Core\Csrf::field() ?>
    <?php if ($system): ?>
      <p class="muted">This is a built-in account that invoices, bills and payments post to. Only its name and note can change.</p>
      <?= field('code', 'Code', $row['code'] ?? '', ['readonly' => true]) ?>
    <?php else: ?>
      <?= field('code', 'Code', $row['code'] ?? '', ['required' => true, 'maxlength' => 20, 'hint' => '2 to 20 letters, digits, dots or dashes. Codes sort the chart of accounts.']) ?>
    <?php endif; ?>
    <?= field('name', 'Name', $row['name'] ?? '', ['required' => true, 'maxlength' => 120]) ?>
    <?php if (!$system): ?>
    <label for="f_account_kind">What kind of account</label>
    <select id="f_account_kind" name="account_kind" required>
      <option value="">Choose...</option>
      <?php foreach (App\Controllers\BooksController::KINDS as $type => $kinds): ?>
        <optgroup label="<?= e(App\Controllers\BooksController::TYPES[$type]) ?>">
          <?php foreach ($kinds as $sub => $label): ?><option value="<?= e($type . ':' . $sub) ?>"<?= $current === $type . ':' . $sub ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <p class="hint">Cash, bank, cheque and wallet accounts appear in the cash and bank lists. Overheads go to profit and loss; direct expenses go into the cost of goods.</p>
    <?php endif; ?>
    <?= field('notes', 'Note (optional)', $row['notes'] ?? '', ['maxlength' => 255]) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save account</button>
      <a class="btn" href="<?= e(url('/books/accounts')) ?>">Cancel</a>
    </div>
  </form>
</section>
