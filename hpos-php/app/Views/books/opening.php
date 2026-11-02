<?php /** @var array $row @var list<array> $lines @var array $accountsAll @var array $customers @var array $suppliers @var ?string $error */
$blank = ['account' => '', 'debit' => '', 'credit' => '', 'party' => '', 'note' => '']; ?>
<div class="page-head"><h1>Opening balances</h1><a class="btn" href="<?= e(url('/books/accounts')) ?>">Chart of accounts</a></div>
<section class="card">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p class="muted">Enter what each account held on the day you start using these books: cash, bank, what customers owed and what you owed suppliers, loans and capital. Debit what you own, credit what you owe. Anything that does not balance goes to <strong>Opening Balance Difference</strong>, so nothing is lost. Stock is not entered here: add it with Adjust stock, reason Opening.</p>
  <form method="post" action="<?= e(url('/books/opening')) ?>" data-journal>
    <?= App\Core\Csrf::field() ?>
    <?= field('date', 'Balances as on', $row['date'] ?? '', ['type' => 'date', 'required' => true, 'max' => date('Y-m-d')]) ?>
    <div id="journal-lines">
      <?php foreach ($lines as $l) { echo journal_line($l, $accountsAll, $customers, $suppliers); } ?>
    </div>
    <template id="journal-line-template"><?= journal_line($blank, $accountsAll, $customers, $suppliers) ?></template>
    <p><button class="btn" type="button" data-add-row="journal-line-template" data-target="journal-lines">Add line</button></p>
    <p class="journal-total" aria-live="polite">Debit <strong data-total="debit">0.00</strong> &middot; Credit <strong data-total="credit">0.00</strong> &middot; Difference <strong data-total="diff">0.00</strong></p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save opening balances</button>
      <a class="btn" href="<?= e(url('/books/accounts')) ?>">Cancel</a>
    </div>
  </form>
</section>
<p class="muted">Opening balances can be entered once. To change them, cancel the Opening voucher in the day book (a reversing entry is posted; nothing is deleted) and enter them again.</p>
