<?php /** @var string $type @var array<string,array> $types @var array $row @var list<array> $lines @var array $accountsAll @var array $cashBank @var array $expenseAccounts @var array $otherAccounts @var array $customers @var array $suppliers @var ?string $error */
$blank = ['account' => '', 'debit' => '', 'credit' => '', 'party' => '', 'note' => '']; ?>
<div class="page-head"><h1><?= e($title) ?></h1><a class="btn" href="<?= e(url('/books/vouchers')) ?>">Day book</a></div>
<nav class="tabs" aria-label="Voucher type">
  <?php foreach ($types as $k => [$label]): ?>
    <a href="<?= e(url('/books/vouchers/new?type=' . $k)) ?>"<?= $type === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<section class="card<?= $type === 'JOURNAL' ? '' : ' card-narrow' ?>">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p class="muted"><?= e($types[$type][1]) ?></p>
  <form method="post" action="<?= e(url('/books/vouchers')) ?>"<?= $type === 'JOURNAL' ? ' data-journal' : '' ?>>
    <?= App\Core\Csrf::field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?= field('date', 'Date', $row['date'] ?? date('Y-m-d'), ['type' => 'date', 'required' => true, 'max' => date('Y-m-d')]) ?>
    <?php if ($type === 'EXPENSE'): ?>
      <?= select_field('account', 'Expense', $row['account'] ?? '', $expenseAccounts, ['required' => true, 'blank' => 'Choose an expense', 'empty' => ['/books/accounts/new', 'Add an expense account']]) ?>
      <?= select_field('paid_from', 'Paid from', $row['paid_from'] ?? '', $cashBank, ['required' => true, 'blank' => 'Choose cash or bank']) ?>
      <?= field('amount', 'Amount', $row['amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]) ?>
    <?php elseif ($type === 'CONTRA'): ?>
      <?= select_field('from_account', 'Money goes out of', $row['from_account'] ?? '', $cashBank, ['required' => true, 'blank' => 'Choose cash or bank']) ?>
      <?= select_field('to_account', 'Money goes into', $row['to_account'] ?? '', $cashBank, ['required' => true, 'blank' => 'Choose cash or bank']) ?>
      <?= field('amount', 'Amount', $row['amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]) ?>
    <?php elseif ($type === 'RECEIPT'): ?>
      <?= select_field('to_account', 'Received into', $row['to_account'] ?? '', $cashBank, ['required' => true, 'blank' => 'Choose cash or bank']) ?>
      <?= select_field('from_account', 'Received for', $row['from_account'] ?? '', $otherAccounts, ['required' => true, 'blank' => 'Choose an account']) ?>
      <?= field('amount', 'Amount', $row['amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]) ?>
      <p class="hint">Money from a customer for an invoice is a payment, not an other receipt: record it under Payments so the invoice is settled too.</p>
    <?php elseif ($type === 'PAYMENT'): ?>
      <?= select_field('from_account', 'Paid from', $row['from_account'] ?? '', $cashBank, ['required' => true, 'blank' => 'Choose cash or bank']) ?>
      <?= select_field('to_account', 'Paid for', $row['to_account'] ?? '', $otherAccounts, ['required' => true, 'blank' => 'Choose an account']) ?>
      <?= field('amount', 'Amount', $row['amount'] ?? '', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]) ?>
      <p class="hint">Money to a supplier for a bill is a payment too: record it under Payments so the bill is settled.</p>
    <?php else: ?>
      <div id="journal-lines">
        <?php foreach ($lines as $l) { echo journal_line($l, $accountsAll, $customers, $suppliers); } ?>
      </div>
      <template id="journal-line-template"><?= journal_line($blank, $accountsAll, $customers, $suppliers) ?></template>
      <p><button class="btn" type="button" data-add-row="journal-line-template" data-target="journal-lines">Add line</button></p>
      <p class="journal-total" aria-live="polite">Debit <strong data-total="debit">0.00</strong> &middot; Credit <strong data-total="credit">0.00</strong> &middot; Difference <strong data-total="diff">0.00</strong></p>
    <?php endif; ?>
    <?= field('narration', 'Narration', $row['narration'] ?? '', ['maxlength' => 500, 'hint' => 'What this is for. It shows in the day book and ledgers.']) ?>
    <?= field('reference', 'Reference (optional)', $row['reference'] ?? '', ['maxlength' => 80, 'hint' => 'Cheque number, bill number, UTR and so on.']) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Post voucher</button>
      <a class="btn" href="<?= e(url('/books/vouchers')) ?>">Cancel</a>
    </div>
  </form>
</section>
