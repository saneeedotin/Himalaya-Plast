<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\View;

/**
 * Credit notes (to a customer, reducing what they owe) and debit notes (to a supplier, reducing what we owe).
 * A note is drafted, then posted; posting writes the voucher and settles the invoice it refers to.
 * These adjust money only; they do not move stock.
 */
final class CreditNoteController extends Controller
{
    public const KINDS = ['SALES' => ['Credit note', 'Credit notes', 'Customer'], 'PURCHASE' => ['Debit note', 'Debit notes', 'Supplier']];
    public const STATUSES = ['DRAFT' => 'Draft', 'POSTED' => 'Posted', 'CANCELLED' => 'Cancelled'];

    public function index(): void
    {
        $kind = $this->kindOf((string) ($_GET['kind'] ?? ''), true);
        $this->render('credit_notes/index', ['title' => 'Credit and debit notes', 'kind' => $kind, 'rows' => Db::rows('sp_credit_note_list', [$this->uid(), $kind])]);
    }

    /** ?kind=SALES|PURCHASE, and ?invoice=ID to start from an invoice (its party and lines are copied). */
    public function create(): void
    {
        $kind = $this->kindOf((string) ($_GET['kind'] ?? 'SALES'));
        $row = ['kind' => $kind, 'note_date' => date('Y-m-d'), 'party_id' => '', 'invoice_id' => '', 'reason' => ''];
        $lines = [['description' => '', 'hsn' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18']];
        $invoice = $this->idParam('invoice');
        if ($invoice !== '') {
            $sets = Db::call($kind === 'SALES' ? 'sp_sales_invoice_get' : 'sp_purchase_invoice_get', [$this->uid(), $invoice]);
            $inv = $sets[0][0] ?? null;
            if ($inv && in_array($inv['status'], ['UNPAID', 'PARTIAL', 'PAID'], true)) {
                $row['party_id'] = $inv[$kind === 'SALES' ? 'customer_id' : 'supplier_id'];
                $row['invoice_id'] = $inv['id'];
                $lines = [];
                foreach ($sets[1] ?? [] as $l) {
                    $lines[] = ['description' => $l['name'], 'hsn' => (string) $l['hsn_code'], 'qty' => trim_zeros($l['qty']), 'rate' => trim_zeros($l['rate']), 'tax_rate' => trim_zeros($l['tax_rate'])];
                }
            }
        }
        $this->render('credit_notes/form', $this->formData($row, $lines, null));
    }

    public function store(): void
    {
        $row = $this->rowFromForm();
        $lines = $this->linesFromForm();
        $this->saving(function () use ($row, $lines) {
            $id = (string) Db::callOut('sp_credit_note_save', [$this->uid(), null, $row['kind'], $row['party_id'], $row['invoice_id'] ?: null, $this->dateOrNull($row['note_date']), $row['reason'] ?: null, json_encode($lines['json'])])[0];
            Flash::success('Saved as a draft. Check it, then post it.');
            redirect('/credit-notes/' . $id);
        }, 'credit_notes/form', $this->formData($row, $lines['form'], null));
    }

    public function show(array $p): void
    {
        [$n, $lines] = $this->load($p['id']);
        $this->render('credit_notes/show', ['title' => $n['note_number'], 'n' => $n, 'lines' => $lines]);
    }

    public function edit(array $p): void
    {
        [$n, $lines] = $this->load($p['id']);
        if ($n['status'] !== 'DRAFT') {
            Flash::error('Only a draft can be edited.');
            redirect('/credit-notes/' . $p['id']);
        }
        $row = ['kind' => $n['kind'], 'note_date' => substr((string) $n['note_date'], 0, 10), 'party_id' => (string) ($n['customer_id'] ?: $n['supplier_id']),
            'invoice_id' => (string) ($n['sales_invoice_id'] ?: $n['purchase_invoice_id']), 'reason' => (string) $n['reason']];
        $form = [];
        foreach ($lines as $l) {
            $form[] = ['description' => $l['description'], 'hsn' => (string) $l['hsn_code'], 'qty' => trim_zeros($l['qty']), 'rate' => trim_zeros($l['rate']), 'tax_rate' => trim_zeros($l['tax_rate'])];
        }
        $this->render('credit_notes/form', $this->formData($row, $form ?: [['description' => '', 'hsn' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18']], $n));
    }

    public function update(array $p): void
    {
        [$n] = $this->load($p['id']);
        $row = $this->rowFromForm();
        $lines = $this->linesFromForm();
        $this->saving(function () use ($p, $row, $lines) {
            Db::callOut('sp_credit_note_save', [$this->uid(), $p['id'], $row['kind'], $row['party_id'], $row['invoice_id'] ?: null, $this->dateOrNull($row['note_date']), $row['reason'] ?: null, json_encode($lines['json'])]);
            Flash::success('Draft saved.');
            redirect('/credit-notes/' . $p['id']);
        }, 'credit_notes/form', $this->formData($row, $lines['form'], $n));
    }

    public function post(array $p): void
    {
        Db::call('sp_credit_note_post', [$this->uid(), $p['id']]);
        Flash::success('Posted. The books and the invoice balance are updated.');
        redirect('/credit-notes/' . $p['id']);
    }

    public function cancel(array $p): void
    {
        Db::call('sp_credit_note_cancel', [$this->uid(), $p['id']]);
        Flash::success('Cancelled. A reversing entry was posted and the invoice balance restored.');
        redirect('/credit-notes/' . $p['id']);
    }

    public function delete(array $p): void
    {
        Db::call('sp_credit_note_delete', [$this->uid(), $p['id']]);
        Flash::success('Draft deleted.');
        redirect('/credit-notes');
    }

    public function print(array $p): void
    {
        [$n, $lines] = $this->load($p['id']);
        $settings = [];
        foreach (Db::rows('sp_settings_list', [$this->uid()]) as $s) {
            $settings[$s['setting_key']] = $s['setting_value'];
        }
        View::render('credit_notes/print', ['title' => $n['note_number'], 'n' => $n, 'lines' => $lines, 'settings' => $settings], 'bare');
    }

    /** @return array{0:array,1:list<array>} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_credit_note_get', [$this->uid(), $id]);
        return [$this->must($sets[0][0] ?? null), $sets[1] ?? []];
    }

    private function kindOf(string $kind, bool $allowAll = false): ?string
    {
        return isset(self::KINDS[$kind]) ? $kind : ($allowAll ? null : 'SALES');
    }

    private function dateOrNull(string $d): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : null;
    }

    private function rowFromForm(): array
    {
        return ['kind' => $this->kindOf($this->str('kind')), 'note_date' => $this->str('note_date'), 'party_id' => $this->str('party_id'),
            'invoice_id' => $this->str('invoice_id'), 'reason' => $this->str('reason')];
    }

    /** @return array{json:list<array>,form:list<array>} */
    private function linesFromForm(): array
    {
        $desc = (array) ($_POST['description'] ?? []);
        $hsn = (array) ($_POST['hsn'] ?? []);
        $qty = (array) ($_POST['qty'] ?? []);
        $rate = (array) ($_POST['rate'] ?? []);
        $tax = (array) ($_POST['tax_rate'] ?? []);
        $json = $form = [];
        foreach ($desc as $i => $d) {
            $l = ['description' => trim((string) $d), 'hsn' => trim((string) ($hsn[$i] ?? '')), 'qty' => trim((string) ($qty[$i] ?? '')),
                'rate' => trim((string) ($rate[$i] ?? '')), 'tax_rate' => trim((string) ($tax[$i] ?? ''))];
            if ($l['description'] === '' && $l['qty'] === '' && $l['rate'] === '') {
                continue;
            }
            $form[] = $l;
            // anything not numeric becomes 0 so the database names the problem line ("every line needs ...")
            $json[] = ['description' => $l['description'], 'hsn' => $l['hsn'], 'qty' => is_numeric($l['qty']) ? (float) $l['qty'] : 0,
                'rate' => is_numeric($l['rate']) ? (float) $l['rate'] : 0, 'taxRate' => is_numeric($l['tax_rate']) ? (float) $l['tax_rate'] : 0];
        }
        return ['json' => $json, 'form' => $form ?: [['description' => '', 'hsn' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '18']]];
    }

    private function formData(array $row, array $lines, ?array $note): array
    {
        $uid = $this->uid();
        $kind = $row['kind'] ?? 'SALES';
        $parties = [];
        $invoices = [];
        if ($kind === 'SALES') {
            foreach (Db::rows('sp_customer_list', [$uid]) as $c) {
                $parties[$c['id']] = $c['name'];
            }
            foreach (Db::rows('sp_sales_invoice_list', [$uid, null]) as $i) {
                if (in_array($i['status'], ['UNPAID', 'PARTIAL', 'PAID'], true)) {
                    $invoices[] = ['id' => $i['id'], 'party' => $i['customer_id'], 'label' => $i['invoice_number'] . ' (' . fmt_num($i['total_amount']) . ')'];
                }
            }
        } else {
            foreach (Db::rows('sp_supplier_list', [$uid]) as $s) {
                $parties[$s['id']] = $s['name'];
            }
            foreach (Db::rows('sp_purchase_invoice_list', [$uid, null]) as $i) {
                if (in_array($i['status'], ['UNPAID', 'PARTIAL', 'PAID'], true)) {
                    $invoices[] = ['id' => $i['id'], 'party' => $i['supplier_id'], 'label' => $i['invoice_number'] . ' (' . fmt_num($i['total_amount']) . ')'];
                }
            }
        }
        $label = self::KINDS[$kind][0];
        return ['title' => $note ? 'Edit ' . $note['note_number'] : 'New ' . strtolower($label), 'row' => $row, 'lines' => $lines, 'note' => $note, 'kind' => $kind,
            'parties' => $parties, 'invoices' => $invoices];
    }
}
