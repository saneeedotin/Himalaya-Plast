<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csv;
use App\Core\Db;
use App\Core\Flash;

/** The books: overview, chart of accounts, ledgers, opening balances and the reports page. */
final class BooksController extends Controller
{
    public const TYPES = ['ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Capital', 'INCOME' => 'Income', 'EXPENSE' => 'Expenses'];
    /** What kinds of account can be added for each type */
    public const KINDS = [
        'ASSET' => ['BANK' => 'Bank account', 'CASH' => 'Cash', 'CHEQUE' => 'Cheques', 'WALLET' => 'Wallet or UPI', 'FIXED_ASSET' => 'Fixed asset', 'GENERAL' => 'Other asset'],
        'LIABILITY' => ['GENERAL' => 'Liability'],
        'EQUITY' => ['GENERAL' => 'Capital or reserve'],
        'INCOME' => ['OTHER_INCOME' => 'Income'],
        'EXPENSE' => ['INDIRECT_EXP' => 'Indirect expense (overheads)', 'DIRECT_EXP' => 'Direct expense (production)'],
    ];

    public function index(): void
    {
        $sets = Db::call('sp_books_dashboard', [$this->uid()]);
        $this->render('books/dashboard', ['title' => 'Books', 'k' => $sets[0][0] ?? [], 'accounts' => $sets[1] ?? [], 'recent' => $sets[2] ?? []]);
    }

    public function reports(): void
    {
        $this->render('books/reports', ['title' => 'Reports']);
    }

    // ------------------------------------------------------------------ chart of accounts

    public function accounts(): void
    {
        $asof = $this->dateParam('asof', date('Y-m-d'));
        $rows = Db::rows('sp_gl_account_list', [$this->uid(), $asof]);
        $this->render('books/accounts', ['title' => 'Chart of accounts', 'rows' => $rows, 'asof' => $asof]);
    }

    public function accountNew(): void
    {
        $this->render('books/account_form', ['title' => 'New account', 'account' => null, 'row' => ['acct_type' => 'EXPENSE', 'subtype' => 'INDIRECT_EXP']]);
    }

    public function accountStore(): void
    {
        $row = $this->accountRow();
        $this->saving(function () use ($row) {
            Db::callOut('sp_gl_account_save', [$this->uid(), null, $row['code'], $row['name'], $row['acct_type'], $row['subtype'], $row['notes'] ?: null]);
            Flash::success('Account added.');
            redirect('/books/accounts');
        }, 'books/account_form', ['title' => 'New account', 'account' => null, 'row' => $row]);
    }

    public function accountEdit(array $p): void
    {
        $a = $this->account($p['id']);
        $this->render('books/account_form', ['title' => 'Edit ' . $a['name'], 'account' => $a, 'row' => $a]);
    }

    public function accountUpdate(array $p): void
    {
        $a = $this->account($p['id']);
        $row = $this->accountRow();
        $this->saving(function () use ($p, $row) {
            Db::callOut('sp_gl_account_save', [$this->uid(), $p['id'], $row['code'], $row['name'], $row['acct_type'], $row['subtype'], $row['notes'] ?: null]);
            Flash::success('Account saved.');
            redirect('/books/accounts');
        }, 'books/account_form', ['title' => 'Edit ' . $a['name'], 'account' => $a, 'row' => $row + $a]);
    }

    public function accountToggle(array $p): void
    {
        $a = $this->account($p['id']);
        Db::call('sp_gl_account_set_active', [$this->uid(), $p['id'], (int) $a['is_active'] === 1 ? 0 : 1]);
        Flash::success((int) $a['is_active'] === 1 ? 'Account switched off.' : 'Account switched on.');
        redirect('/books/accounts');
    }

    // ------------------------------------------------------------------ ledger

    /** One account's ledger (a cash or bank account's is its cash book or bank book), with a running balance. */
    public function ledger(): void
    {
        $uid = $this->uid();
        $accounts = Db::rows('sp_gl_account_pick', [$uid, 'ALL']);
        $account = $this->idParam('account');
        $from = $this->dateParam('from', fy_start());
        $to = $this->dateParam('to', date('Y-m-d'));
        $party = (string) ($_GET['party'] ?? '');
        [$ptype, $pid] = preg_match('/^(CUSTOMER|SUPPLIER):([0-9a-f-]{36})$/i', $party, $m) ? [strtoupper($m[1]), $m[2]] : [null, null];
        $data = ['title' => 'Ledger', 'accounts' => $accounts, 'account' => $account, 'from' => $from, 'to' => $to, 'party' => $party, 'head' => null, 'rows' => [], 'parties' => []];
        $subtype = '';
        foreach ($accounts as $a) {
            if ($a['id'] === $account) {
                $subtype = $a['subtype'];
            }
        }
        if ($subtype === 'DEBTORS') {
            foreach (Db::rows('sp_customer_list', [$uid]) as $c) {
                $data['parties']['CUSTOMER:' . $c['id']] = $c['name'];
            }
        } elseif ($subtype === 'CREDITORS') {
            foreach (Db::rows('sp_supplier_list', [$uid]) as $s) {
                $data['parties']['SUPPLIER:' . $s['id']] = $s['name'];
            }
        }
        if ($account !== '') {
            $sets = Db::call('sp_gl_ledger', [$uid, $account, $from, $to, $ptype, $pid]);
            $data['head'] = $sets[0][0] ?? null;
            $data['rows'] = $sets[1] ?? [];
            if (($_GET['format'] ?? '') === 'csv' && $data['head']) {
                $rows = [['', 'Opening balance', '', '', '', '', '', drcr($data['head']['opening'])]];
                foreach ($data['rows'] as $r) {
                    $rows[] = [substr((string) $r['voucher_date'], 0, 10), $r['voucher_number'], $r['voucher_type'], $r['against'], $r['narration'], (float) $r['debit'] ?: '', (float) $r['credit'] ?: '', drcr($r['balance'])];
                }
                Csv::send('ledger_' . $data['head']['code'] . '_' . $from . '_' . $to . '.csv', ['Date', 'Voucher', 'Type', 'Against', 'Narration', 'Debit', 'Credit', 'Balance'], $rows);
            }
        }
        $this->render('books/ledger', $data);
    }

    // ------------------------------------------------------------------ opening balances

    public function opening(): void
    {
        $this->render('books/opening', $this->openingData(['date' => fy_start()], [['account' => '', 'debit' => '', 'credit' => '', 'party' => '']]));
    }

    public function saveOpening(): void
    {
        $date = $this->str('date');
        $lines = $this->journalLines();
        $this->saving(function () use ($date, $lines) {
            Db::callOut('sp_opening_balances_save', [$this->uid(), $date ?: null, json_encode($lines['json']), client_ip()]);
            Flash::success('Opening balances saved. Any difference went to Opening Balance Difference.');
            redirect('/books/accounts');
        }, 'books/opening', $this->openingData(['date' => $date], $lines['form']));
    }

    private function openingData(array $row, array $lines): array
    {
        return ['title' => 'Opening balances', 'row' => $row, 'lines' => $lines] + VoucherController::pickLists($this->uid());
    }

    // ------------------------------------------------------------------ shared with vouchers

    /**
     * Journal-style lines from the form: account[], debit[], credit[], party[] ("CUSTOMER:<id>"), note[].
     * @return array{json:list<array>,form:list<array>}
     */
    public function journalLines(): array
    {
        $acc = (array) ($_POST['account'] ?? []);
        $dr = (array) ($_POST['debit'] ?? []);
        $cr = (array) ($_POST['credit'] ?? []);
        $party = (array) ($_POST['party'] ?? []);
        $note = (array) ($_POST['note'] ?? []);
        $json = $form = [];
        foreach ($acc as $i => $a) {
            $a = trim((string) $a);
            $d = trim((string) ($dr[$i] ?? ''));
            $c = trim((string) ($cr[$i] ?? ''));
            $p = trim((string) ($party[$i] ?? ''));
            $n = trim((string) ($note[$i] ?? ''));
            if ($a === '' && $d === '' && $c === '') {
                continue;
            }
            $form[] = ['account' => $a, 'debit' => $d, 'credit' => $c, 'party' => $p, 'note' => $n];
            // anything that is not a number counts as nothing; the database then says which line needs an amount
            $line = ['account' => $a, 'debit' => is_numeric($d) ? (float) $d : 0, 'credit' => is_numeric($c) ? (float) $c : 0, 'note' => $n];
            if (preg_match('/^(CUSTOMER|SUPPLIER):([0-9a-f-]{36})$/i', $p, $m)) {
                $line['partyType'] = strtoupper($m[1]);
                $line['partyId'] = $m[2];
            }
            $json[] = $line;
        }
        return ['json' => $json, 'form' => $form ?: [['account' => '', 'debit' => '', 'credit' => '', 'party' => '', 'note' => '']]];
    }

    private function account(string $id): array
    {
        return $this->must(Db::row('sp_gl_account_get', [$this->uid(), $id]));
    }

    private function accountRow(): array
    {
        // one "kind" menu carries both the type and the sub-kind, e.g. EXPENSE:INDIRECT_EXP
        [$type, $sub] = array_pad(explode(':', $this->str('account_kind'), 2), 2, '');
        return ['code' => $this->str('code'), 'name' => $this->str('name'), 'acct_type' => $type, 'subtype' => $sub, 'notes' => $this->str('notes')];
    }
}
