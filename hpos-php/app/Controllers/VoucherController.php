<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Csv;
use App\Core\Db;
use App\Core\Flash;

/** The day book and vouchers entered by hand: expense, contra, receipt, payment and journal. */
final class VoucherController extends Controller
{
    /** Types a person can enter, with what each is for. Sales, purchase, receipt-from-customer and so on come from documents. */
    public const TYPES = [
        'EXPENSE' => ['Expense', 'Money spent on rent, wages, fuel and so on, from a cash or bank account.'],
        'CONTRA' => ['Contra', 'Money moved between your own cash, bank, cheque and wallet accounts.'],
        'RECEIPT' => ['Other receipt', 'Money that came in and is not from a customer invoice: capital, a loan, interest, other income.'],
        'PAYMENT' => ['Other payment', 'Money that went out and is not to a supplier bill: loan repayment, drawings, deposits.'],
        'JOURNAL' => ['Journal', 'An adjustment between any accounts, for your accountant. Debits must equal credits.'],
    ];
    public const ALL_TYPES = ['SALES' => 'Sales', 'PURCHASE' => 'Purchase', 'RECEIPT' => 'Receipt', 'PAYMENT' => 'Payment', 'JOURNAL' => 'Journal', 'CONTRA' => 'Contra',
        'EXPENSE' => 'Expense', 'CREDIT_NOTE' => 'Credit note', 'DEBIT_NOTE' => 'Debit note', 'OPENING' => 'Opening'];

    public function index(): void
    {
        $from = $this->dateParam('from', date('Y-m-d', strtotime('-30 days')));
        $to = $this->dateParam('to', date('Y-m-d'));
        $type = (string) ($_GET['type'] ?? '');
        $type = isset(self::ALL_TYPES[$type]) ? $type : '';
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = Db::rows('sp_gl_day_book', [$this->uid(), $from, $to, $type ?: null, $q !== '' ? mb_substr($q, 0, 100) : null]);
        if (($_GET['format'] ?? '') === 'csv') {
            $out = [];
            foreach ($rows as $r) {
                $out[] = [substr((string) $r['voucher_date'], 0, 10), $r['voucher_number'], $r['voucher_type'], $r['account_code'], $r['account_name'], $r['party_name'], $r['narration'], (float) $r['debit'] ?: '', (float) $r['credit'] ?: ''];
            }
            Csv::send("day_book_{$from}_{$to}.csv", ['Date', 'Voucher', 'Type', 'Account code', 'Account', 'Party', 'Narration', 'Debit', 'Credit'], $out);
        }
        $vouchers = [];
        foreach ($rows as $r) {
            $vouchers[$r['voucher_id']]['head'] = $r;
            $vouchers[$r['voucher_id']]['lines'][] = $r;
        }
        $this->render('books/day_book', ['title' => 'Day book', 'vouchers' => $vouchers, 'from' => $from, 'to' => $to, 'type' => $type, 'q' => $q, 'types' => self::ALL_TYPES, 'truncated' => count($rows) >= 3000]);
    }

    public function create(): void
    {
        $type = $this->typeParam();
        $this->render('books/voucher_form', $this->formData($type, ['date' => date('Y-m-d')], [['account' => '', 'debit' => '', 'credit' => '', 'party' => '', 'note' => '']]));
    }

    public function store(): void
    {
        $type = $this->typeParam((string) ($_POST['type'] ?? ''));
        $row = ['date' => $this->str('date'), 'narration' => $this->str('narration'), 'reference' => $this->str('reference'), 'amount' => $this->str('amount'),
            'account' => $this->str('account'), 'from_account' => $this->str('from_account'), 'to_account' => $this->str('to_account'), 'paid_from' => $this->str('paid_from')];
        $journal = (new BooksController())->journalLines();
        $this->saving(function () use ($type, $row, $journal) {
            $amount = is_numeric($row['amount']) ? (float) $row['amount'] : 0.0;
            if ($type !== 'JOURNAL' && $amount <= 0) {
                throw new AppError('The amount must be above zero.');
            }
            $lines = match ($type) {
                'EXPENSE' => [['account' => $row['account'], 'debit' => $amount, 'credit' => 0], ['account' => $row['paid_from'], 'debit' => 0, 'credit' => $amount]],
                'CONTRA' => [['account' => $row['to_account'], 'debit' => $amount, 'credit' => 0], ['account' => $row['from_account'], 'debit' => 0, 'credit' => $amount]],
                'RECEIPT' => [['account' => $row['to_account'], 'debit' => $amount, 'credit' => 0], ['account' => $row['from_account'], 'debit' => 0, 'credit' => $amount]],
                'PAYMENT' => [['account' => $row['to_account'], 'debit' => $amount, 'credit' => 0], ['account' => $row['from_account'], 'debit' => 0, 'credit' => $amount]],
                default => $journal['json'],
            };
            $id = (string) Db::callOut('sp_voucher_create', [$this->uid(), $type, $row['date'] ?: null, $row['narration'] ?: null, $row['reference'] ?: null, json_encode($lines), client_ip()])[0];
            Flash::success('Voucher posted.');
            redirect('/books/vouchers/' . $id);
        }, 'books/voucher_form', $this->formData($type, $row, $journal['form']));
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_voucher_get', [$this->uid(), $p['id']]);
        $v = $this->must($sets[0][0] ?? null);
        $this->render('books/voucher', ['title' => $v['voucher_number'], 'v' => $v, 'lines' => $sets[1] ?? [], 'types' => self::ALL_TYPES]);
    }

    public function cancel(array $p): void
    {
        Db::call('sp_voucher_cancel', [$this->uid(), $p['id'], $this->str('reason') ?: null, client_ip()]);
        Flash::success('Voucher cancelled. A reversing entry was posted; nothing was deleted.');
        redirect('/books/vouchers/' . $p['id']);
    }

    /** Account lists for the forms. Cash and bank accounts, expense accounts and the rest, plus customers and suppliers for journal lines. */
    public static function pickLists(string $uid): array
    {
        $all = $cash = $expense = $other = [];
        foreach (Db::rows('sp_gl_account_pick', [$uid, 'ALL']) as $a) {
            $label = $a['code'] . ' ' . $a['name'];
            $all[$a['id']] = ['label' => $label, 'sub' => $a['subtype']];
            if (in_array($a['subtype'], ['CASH', 'BANK', 'CHEQUE', 'WALLET'], true)) {
                $cash[$a['id']] = $label;
            }
            if ($a['acct_type'] === 'EXPENSE' && !in_array($a['subtype'], ['PURCHASES', 'PURCHASE_RETURN'], true)) {
                $expense[$a['id']] = $label;
            }
            // customers and suppliers are settled through Payments (so invoices are settled too), not through a loose voucher
            if (!in_array($a['subtype'], ['CASH', 'BANK', 'CHEQUE', 'WALLET', 'DEBTORS', 'CREDITORS'], true)) {
                $other[$a['id']] = $label;
            }
        }
        $customers = $suppliers = [];
        foreach (Db::rows('sp_customer_list', [$uid]) as $c) {
            $customers['CUSTOMER:' . $c['id']] = $c['name'];
        }
        foreach (Db::rows('sp_supplier_list', [$uid]) as $s) {
            $suppliers['SUPPLIER:' . $s['id']] = $s['name'];
        }
        return ['accountsAll' => $all, 'cashBank' => $cash, 'expenseAccounts' => $expense, 'otherAccounts' => $other, 'customers' => $customers, 'suppliers' => $suppliers];
    }

    private function typeParam(?string $given = null): string
    {
        $t = $given ?? (string) ($_GET['type'] ?? 'EXPENSE');
        return isset(self::TYPES[$t]) ? $t : 'EXPENSE';
    }

    private function formData(string $type, array $row, array $lines): array
    {
        return ['title' => 'New ' . strtolower(self::TYPES[$type][0]), 'type' => $type, 'types' => self::TYPES, 'row' => $row, 'lines' => $lines] + self::pickLists($this->uid());
    }
}
