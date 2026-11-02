<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csv;
use App\Core\Db;

/** Financial statements and tax reports, all read from the ledger. Every one can be downloaded as CSV with ?format=csv. */
final class BookReportController extends Controller
{
    public const GST_TABS = ['summary' => 'Summary', 'sales' => 'Sales register', 'purchases' => 'Purchase register', 'hsn' => 'HSN summary'];

    public function trialBalance(): void
    {
        $asof = $this->dateParam('asof', date('Y-m-d'));
        $rows = Db::rows('sp_gl_trial_balance', [$this->uid(), $asof]);
        $dr = $cr = 0.0;
        foreach ($rows as &$r) {
            $b = round((float) $r['balance'], 2);
            // a trial balance shows each account's net in one column
            $r['dr'] = $b > 0 ? $b : 0.0;
            $r['cr'] = $b < 0 ? -$b : 0.0;
            $dr += $r['dr'];
            $cr += $r['cr'];
        }
        unset($r);
        if ($this->wantsCsv()) {
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['code'], $r['name'], $r['dr'] ?: '', $r['cr'] ?: ''];
            }
            $out[] = ['', 'Total', round($dr, 2), round($cr, 2)];
            Csv::send("trial_balance_$asof.csv", ['Code', 'Account', 'Debit', 'Credit'], $out);
        }
        $this->render('books/trial_balance', ['title' => 'Trial balance', 'rows' => $rows, 'asof' => $asof, 'dr' => round($dr, 2), 'cr' => round($cr, 2)]);
    }

    /**
     * Trading account (sales against the cost of goods sold) then profit and loss (gross profit, other income, overheads).
     * Cost of goods = opening stock + purchases less returns + direct expenses - closing stock.
     */
    public function profitLoss(): void
    {
        $from = $this->dateParam('from', fy_start());
        $to = $this->dateParam('to', date('Y-m-d'));
        $sets = Db::call('sp_gl_profit_loss', [$this->uid(), $from, $to]);
        $stock = $sets[1][0] ?? ['opening_stock' => 0, 'closing_stock' => 0];
        $groups = ['sales' => [], 'purchases' => [], 'direct' => [], 'other_income' => [], 'indirect' => []];
        foreach ($sets[0] ?? [] as $r) {
            match ($r['subtype']) {
                'SALES', 'SALES_RETURN' => $groups['sales'][] = $r,
                'PURCHASES', 'PURCHASE_RETURN' => $groups['purchases'][] = $r,
                'DIRECT_EXP' => $groups['direct'][] = $r,
                'OTHER_INCOME' => $groups['other_income'][] = $r,
                default => $groups['indirect'][] = $r,
            };
        }
        $sum = static fn (array $rows): float => round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows)), 2);
        $t = [
            'sales' => $sum($groups['sales']), 'purchases' => $sum($groups['purchases']), 'direct' => $sum($groups['direct']),
            'opening' => round((float) $stock['opening_stock'], 2), 'closing' => round((float) $stock['closing_stock'], 2),
            'other_income' => $sum($groups['other_income']), 'indirect' => $sum($groups['indirect']),
        ];
        $t['cogs'] = round($t['opening'] + $t['purchases'] + $t['direct'] - $t['closing'], 2);
        $t['gross'] = round($t['sales'] - $t['cogs'], 2);
        $t['net'] = round($t['gross'] + $t['other_income'] - $t['indirect'], 2);
        if ($this->wantsCsv()) {
            $out = [['Sales (net of returns)', $t['sales']], ['Opening stock', $t['opening']], ['Purchases (net of returns)', $t['purchases']], ['Direct expenses', $t['direct']],
                ['Closing stock', $t['closing']], ['Cost of goods sold', $t['cogs']], ['Gross profit', $t['gross']], ['Other income', $t['other_income']],
                ['Indirect expenses', $t['indirect']], ['Net profit', $t['net']]];
            Csv::send("profit_loss_{$from}_{$to}.csv", ['Line', 'Amount'], $out);
        }
        $this->render('books/profit_loss', ['title' => 'Profit and loss', 'from' => $from, 'to' => $to, 'g' => $groups, 't' => $t]);
    }

    public function balanceSheet(): void
    {
        $asof = $this->dateParam('asof', date('Y-m-d'));
        $sets = Db::call('sp_gl_balance_sheet', [$this->uid(), $asof]);
        $extra = $sets[1][0] ?? ['stock_value' => 0, 'stock_introduced' => 0, 'books_profit' => 0];
        $assets = $liabilities = [];
        foreach ($sets[0] ?? [] as $r) {
            $b = round((float) $r['balance'], 2);
            if ($r['acct_type'] === 'ASSET') {
                $assets[] = $r + ['shown' => $b];
            } else {
                $liabilities[] = $r + ['shown' => -$b];
            }
        }
        $stock = round((float) $extra['stock_value'], 2);
        $introduced = round((float) $extra['stock_introduced'], 2);
        // profit to date = what the ledger earned, adjusted for stock built up since opening stock was entered
        $profit = round((float) $extra['books_profit'] + $stock - $introduced, 2);
        $assetTotal = round(array_sum(array_column($assets, 'shown')) + $stock, 2);
        $liabTotal = round(array_sum(array_column($liabilities, 'shown')) + $profit + $introduced, 2);
        $data = ['title' => 'Balance sheet', 'asof' => $asof, 'assets' => $assets, 'liabilities' => $liabilities, 'stock' => $stock, 'introduced' => $introduced,
            'profit' => $profit, 'assetTotal' => $assetTotal, 'liabTotal' => $liabTotal, 'difference' => round($assetTotal - $liabTotal, 2)];
        if ($this->wantsCsv()) {
            $out = [];
            foreach ($assets as $r) {
                $out[] = ['Asset', $r['code'], $r['name'], $r['shown']];
            }
            $out[] = ['Asset', '', 'Closing stock (at standard cost)', $stock];
            foreach ($liabilities as $r) {
                $out[] = [$r['acct_type'] === 'EQUITY' ? 'Capital' : 'Liability', $r['code'], $r['name'], $r['shown']];
            }
            $out[] = ['Capital', '', 'Stock brought in as opening stock', $introduced];
            $out[] = ['Capital', '', 'Profit to date', $profit];
            $out[] = ['', '', 'Total assets', $assetTotal];
            $out[] = ['', '', 'Total liabilities and capital', $liabTotal];
            Csv::send("balance_sheet_$asof.csv", ['Section', 'Code', 'Account', 'Amount'], $out);
        }
        $this->render('books/balance_sheet', $data);
    }

    public function gst(): void
    {
        $from = $this->dateParam('from', date('Y-m-01'));
        $to = $this->dateParam('to', date('Y-m-d'));
        $tab = (string) ($_GET['tab'] ?? 'summary');
        $tab = isset(self::GST_TABS[$tab]) ? $tab : 'summary';
        $args = [$this->uid(), $from, $to];
        $data = ['title' => 'GST', 'from' => $from, 'to' => $to, 'tab' => $tab, 'tabs' => self::GST_TABS, 'rows' => [], 's' => null];
        if ($tab === 'summary') {
            $data['s'] = Db::row('sp_gst_summary', $args);
        } else {
            $data['rows'] = Db::rows(['sales' => 'sp_gst_sales_register', 'purchases' => 'sp_gst_purchase_register', 'hsn' => 'sp_gst_hsn_summary'][$tab], $args);
        }
        if ($this->wantsCsv()) {
            $out = [];
            if ($tab === 'summary') {
                $s = $data['s'] ?? [];
                foreach (['CGST' => 'cgst', 'SGST' => 'sgst', 'IGST' => 'igst'] as $label => $k) {
                    $out[] = [$label, (float) ($s["out_$k"] ?? 0), (float) ($s["in_$k"] ?? 0), round((float) ($s["out_$k"] ?? 0) - (float) ($s["in_$k"] ?? 0), 2)];
                }
                Csv::send("gst_summary_{$from}_{$to}.csv", ['Head', 'Output tax', 'Input tax', 'Payable'], $out);
            }
            if ($tab === 'hsn') {
                foreach ($data['rows'] as $r) {
                    $out[] = [$r['hsn'], $r['uom'], (float) $r['qty'], (float) $r['taxable'], (float) $r['cgst'], (float) $r['sgst'], (float) $r['igst'], (float) $r['total_tax']];
                }
                Csv::send("gst_hsn_{$from}_{$to}.csv", ['HSN', 'Unit', 'Quantity', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total tax'], $out);
            }
            foreach ($data['rows'] as $r) {
                $out[] = [$r['doc_type'], $r['doc_no'], (string) $r['doc_date'], $r['party'], $r['gstin'], (float) $r['taxable'], (float) $r['cgst'], (float) $r['sgst'], (float) $r['igst'], (float) $r['total']];
            }
            Csv::send("gst_{$tab}_{$from}_{$to}.csv", ['Type', 'Number', 'Date', 'Party', 'GSTIN', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total'], $out);
        }
        $this->render('books/gst', $data);
    }

    /** A customer's or supplier's statement of account. ?party=CUSTOMER:<id> or SUPPLIER:<id> */
    public function statement(): void
    {
        $uid = $this->uid();
        $from = $this->dateParam('from', fy_start());
        $to = $this->dateParam('to', date('Y-m-d'));
        $party = (string) ($_GET['party'] ?? '');
        [$ptype, $pid] = preg_match('/^(CUSTOMER|SUPPLIER):([0-9a-f-]{36})$/i', $party, $m) ? [strtoupper($m[1]), $m[2]] : [null, null];
        $parties = [];
        foreach (Db::rows('sp_customer_list', [$uid]) as $c) {
            $parties['Customers']['CUSTOMER:' . $c['id']] = $c['name'];
        }
        foreach (Db::rows('sp_supplier_list', [$uid]) as $s) {
            $parties['Suppliers']['SUPPLIER:' . $s['id']] = $s['name'];
        }
        $data = ['title' => 'Statement of account', 'from' => $from, 'to' => $to, 'party' => $party, 'parties' => $parties, 'head' => null, 'rows' => []];
        if ($ptype !== null) {
            $sets = Db::call('sp_gl_party_statement', [$uid, $ptype, $pid, $from, $to]);
            $data['head'] = $sets[0][0] ?? null;
            $data['rows'] = $sets[1] ?? [];
            if ($this->wantsCsv() && $data['head']) {
                $out = [['', 'Opening balance', '', '', '', '', drcr($data['head']['opening'])]];
                foreach ($data['rows'] as $r) {
                    $out[] = [substr((string) $r['voucher_date'], 0, 10), $r['voucher_number'], $r['voucher_type'], $r['narration'], (float) $r['debit'] ?: '', (float) $r['credit'] ?: '', drcr($r['balance'])];
                }
                Csv::send('statement_' . $ptype . '_' . $from . '_' . $to . '.csv', ['Date', 'Voucher', 'Type', 'Narration', 'Debit', 'Credit', 'Balance'], $out);
            }
        }
        $this->render('books/statement', $data);
    }

    private function wantsCsv(): bool
    {
        return ($_GET['format'] ?? '') === 'csv';
    }
}
