<?php /** @var list<array> $rows @var string $asof */
$types = App\Controllers\BooksController::TYPES;
$canCreate = can('Accounts', 'create');
$canUpdate = can('Accounts', 'update'); ?>
<div class="page-head">
  <h1>Chart of accounts</h1>
  <div class="head-actions">
    <?php if ($canCreate): ?><a class="btn" href="<?= e(url('/books/opening')) ?>">Opening balances</a><a class="btn btn-primary" href="<?= e(url('/books/accounts/new')) ?>"><?= icon('plus') ?>New account</a><?php endif; ?>
  </div>
</div>
<form method="get" action="<?= e(url('/books/accounts')) ?>" class="filter-row">
  <div><?= field('asof', 'Balances as on', $asof, ['type' => 'date']) ?></div>
  <button class="btn" type="submit">Show</button>
</form>
<?php foreach ($types as $type => $heading): $list = array_filter($rows, static fn (array $r): bool => $r['acct_type'] === $type); ?>
<h2><?= e($heading) ?></h2>
<div class="card flush">
<table class="table">
  <thead><tr><th>Code</th><th>Account</th><th>Kind</th><th class="num">Balance</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $a): ?>
    <tr<?= (int) $a['is_active'] === 0 ? ' class="muted"' : '' ?>>
      <td><?= e($a['code']) ?></td>
      <td><a href="<?= e(url('/books/ledger?account=' . $a['id'])) ?>"><?= e($a['name']) ?></a> <?php if ((int) $a['is_system'] === 1): ?><?= pill('Built in', 'neutral') ?><?php endif; ?><?php if ((int) $a['is_active'] === 0): ?><?= pill('Off', 'warn') ?><?php endif; ?></td>
      <td><?= e(ucfirst(strtolower(str_replace('_', ' ', $a['subtype'])))) ?></td>
      <td class="num"><?= e(drcr($a['balance'])) ?></td>
      <td class="actions">
        <?php if ($canUpdate): ?>
          <a href="<?= e(url('/books/accounts/' . $a['id'] . '/edit')) ?>">Edit</a>
          <?php if ((int) $a['is_system'] === 0): ?><?= action_form('/books/accounts/' . $a['id'] . '/toggle', (int) $a['is_active'] === 1 ? 'Switch off' : 'Switch on', null, 'btn btn-quiet') ?><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="5" class="muted">None.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php endforeach; ?>
<p class="muted">Balances show Dr (debit) or Cr (credit). Built-in accounts are used by invoices, bills and payments and cannot be switched off or renamed in kind. Stock is valued separately at standard cost and appears on the balance sheet.</p>
