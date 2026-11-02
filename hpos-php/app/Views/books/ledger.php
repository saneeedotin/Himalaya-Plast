<?php /** @var list<array> $accounts @var string $account @var string $from @var string $to @var string $party @var ?array $head @var list<array> $rows @var array<string,string> $parties */
$types = App\Controllers\VoucherController::ALL_TYPES;
$options = [];
foreach ($accounts as $a) {
    $options[$a['id']] = $a['code'] . ' ' . $a['name'];
}
$debit = array_sum(array_map(static fn (array $r): float => (float) $r['debit'], $rows));
$credit = array_sum(array_map(static fn (array $r): float => (float) $r['credit'], $rows));
$isCashBank = $head && in_array($head['subtype'], ['CASH', 'BANK', 'CHEQUE', 'WALLET'], true); ?>
<div class="page-head">
  <h1><?= $head ? e($head['name']) . ' ' . ($isCashBank ? '<span class="muted">(' . ($head['subtype'] === 'CASH' ? 'cash book' : 'bank book') . ')</span>' : '') : 'Ledger' ?></h1>
  <a class="btn" href="<?= e(url('/books/accounts')) ?>">Chart of accounts</a>
</div>
<form method="get" action="<?= e(url('/books/ledger')) ?>" class="filter-row">
  <div><?= select_field('account', 'Account', $account, $options, ['blank' => 'Choose an account']) ?></div>
  <?php if ($parties): ?><div><?= select_field('party', $head && $head['subtype'] === 'DEBTORS' ? 'Customer' : 'Supplier', $party, $parties, ['blank' => 'All']) ?></div><?php endif; ?>
  <div><?= field('from', 'From', $from, ['type' => 'date']) ?></div>
  <div><?= field('to', 'To', $to, ['type' => 'date']) ?></div>
  <button class="btn" type="submit">Show</button>
  <?php if ($head): ?><a class="btn" href="<?= e(url('/books/ledger?' . http_build_query(array_filter(['account' => $account, 'party' => $party, 'from' => $from, 'to' => $to, 'format' => 'csv'])))) ?>"><?= icon('download') ?>Download CSV</a><?php endif; ?>
</form>
<?php if (!$head): ?>
  <div class="card"><p class="muted">Choose an account to see every entry on it with a running balance. Pick a cash or bank account for the cash book or bank book.</p></div>
<?php else: ?>
<div class="card flush">
<table class="table">
  <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Against</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
  <tbody>
    <tr class="subtotal"><td colspan="7">Opening balance</td><td class="num"><?= e(drcr($head['opening'])) ?></td></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(substr((string) $r['voucher_date'], 0, 10)) ?></td>
      <td><a href="<?= e(url('/books/vouchers/' . $r['voucher_id'])) ?>"><?= e($r['voucher_number']) ?></a></td>
      <td><?= e($types[$r['voucher_type']] ?? $r['voucher_type']) ?></td>
      <td><?= e($r['against']) ?></td>
      <td><?= e($r['narration']) ?></td>
      <td class="num"><?= (float) $r['debit'] > 0 ? e(fmt_num($r['debit'])) : '' ?></td>
      <td class="num"><?= (float) $r['credit'] > 0 ? e(fmt_num($r['credit'])) : '' ?></td>
      <td class="num"><?= e(drcr($r['balance'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No entries in this period.</td></tr><?php endif; ?>
    <tr class="subtotal"><td colspan="5">Total for the period</td><td class="num"><?= e(fmt_num($debit)) ?></td><td class="num"><?= e(fmt_num($credit)) ?></td><td class="num"><?= e(drcr((float) $head['opening'] + $debit - $credit)) ?></td></tr>
  </tbody>
</table>
</div>
<p class="muted">Dr means debit, Cr means credit. A cancelled voucher stays here with the reversing entry beside it.</p>
<?php endif; ?>
