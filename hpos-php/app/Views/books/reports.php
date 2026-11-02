<?php
$cards = [
    ['Statements', [
        ['Trial balance', 'Every account\'s balance; debits equal credits.', '/books/reports/trial-balance', 'scale'],
        ['Profit and loss', 'Sales, cost of goods, overheads and net profit for a period.', '/books/reports/profit-loss', 'bar-chart'],
        ['Balance sheet', 'What you own, owe and the capital behind it, on a date.', '/books/reports/balance-sheet', 'landmark'],
    ]],
    ['Books', [
        ['Day book', 'Every voucher in date order, filter by type.', '/books/vouchers', 'book-open'],
        ['Ledger, cash book, bank book', 'One account with a running balance.', '/books/ledger', 'list'],
        ['Customer or supplier statement', 'What one party has been billed and has paid.', '/books/reports/statement', 'users'],
    ]],
    ['Tax', [
        ['GST summary', 'Output tax against input tax for a period.', '/books/reports/gst', 'percent'],
        ['Sales and purchase registers', 'Every invoice, bill and note with its GST.', '/books/reports/gst?tab=sales', 'file-spreadsheet'],
        ['HSN summary', 'Sales quantity, value and tax by HSN code.', '/books/reports/gst?tab=hsn', 'layers'],
    ]],
    ['Money owed', [
        ['Receivables ageing', 'Unpaid customer invoices by how late they are.', '/receivables', 'activity'],
        ['Payables ageing', 'Unpaid supplier bills by how late they are.', '/payables', 'activity'],
        ['Export to Book Keeper or Tally', 'Download vouchers for your accountant\'s software.', '/exports', 'download'],
    ]],
]; ?>
<div class="page-head"><h1>Reports</h1><a class="btn" href="<?= e(url('/books')) ?>">Books</a></div>
<?php foreach ($cards as [$heading, $items]): ?>
<h2><?= e($heading) ?></h2>
<div class="shortcuts">
  <?php foreach ($items as [$label, $sub, $path, $ico]): ?>
    <a class="shortcut" href="<?= e(url($path)) ?>"><span class="ico ico-teal"><?= icon($ico) ?></span><span><b><?= e($label) ?></b><small class="muted"><?= e($sub) ?></small></span></a>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
