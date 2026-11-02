<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Supplier bills. */
final class PurchaseInvoiceController extends Controller
{
    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = isset(InvoiceController::STATUSES[$status]) ? $status : null;
        $this->render('purchase_invoices/index', ['title' => 'Purchase invoices', 'invoices' => Db::rows('sp_purchase_invoice_list', [$this->uid(), $status]), 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('purchase_invoices/form', $this->formData(['supplier_id' => (string) ($_GET['supplier'] ?? '')], []));
    }

    public function store(): void
    {
        $row = ['supplier_id' => $this->str('supplier_id'), 'invoice_date' => $this->str('invoice_date'), 'due_date' => $this->str('due_date'), 'total_amount' => $this->str('total_amount')];
        $ids = (array) ($_POST['item_id'] ?? []);
        $qtys = (array) ($_POST['qty'] ?? []);
        $rates = (array) ($_POST['rate'] ?? []);
        $taxes = (array) ($_POST['tax_rate'] ?? []);
        $lines = [];
        foreach ($ids as $i => $id) {
            $l = ['item_id' => trim((string) $id), 'qty' => trim((string) ($qtys[$i] ?? '')), 'rate' => trim((string) ($rates[$i] ?? '')), 'tax_rate' => trim((string) ($taxes[$i] ?? ''))];
            if ($l['item_id'] !== '' || $l['qty'] !== '' || $l['rate'] !== '') {
                $lines[] = $l;
            }
        }
        $this->saving(function () use ($row, $lines) {
            if ($row['supplier_id'] === '') {
                throw new AppError('Choose a supplier.');
            }
            $items = [];
            foreach ($lines as $l) {
                if ($l['item_id'] === '' || !is_numeric($l['qty']) || (float) $l['qty'] <= 0 || !is_numeric($l['rate']) || (float) $l['rate'] < 0
                    || ($l['tax_rate'] !== '' && (!is_numeric($l['tax_rate']) || (float) $l['tax_rate'] < 0 || (float) $l['tax_rate'] > 100))) {
                    throw new AppError('Every line needs an item, a quantity above zero, a rate and a tax rate from 0 to 100.');
                }
                $items[] = ['itemId' => $l['item_id'], 'qty' => (float) $l['qty'], 'rate' => (float) $l['rate'], 'taxRate' => $l['tax_rate'] === '' ? 18.0 : (float) $l['tax_rate']];
            }
            if (!$items && (!is_numeric($row['total_amount']) || (float) $row['total_amount'] <= 0)) {
                throw new AppError('Enter the lines from the bill, or just its total amount.');
            }
            $id = (string) Db::callOut('sp_purchase_invoice_create', [
                $this->uid(), $row['supplier_id'], $this->dt('invoice_date'), $this->dt('due_date'),
                $items ? null : $row['total_amount'], json_encode($items),
            ])[0];
            Flash::success('Supplier bill saved as a draft.');
            redirect('/purchase-invoices/' . $id);
        }, 'purchase_invoices/form', $this->formData($row, $lines));
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_purchase_invoice_get', [$this->uid(), $p['id']]);
        $inv = $this->must($sets[0][0] ?? null);
        $this->render('purchase_invoices/show', ['title' => $inv['invoice_number'], 'inv' => $inv, 'lines' => $sets[1] ?? [], 'payments' => $sets[2] ?? []]);
    }

    public function submit(array $p): void
    {
        Db::call('sp_purchase_invoice_submit', [$this->uid(), $p['id']]);
        Flash::success('Bill posted. It now shows under payables.');
        redirect('/purchase-invoices/' . $p['id']);
    }

    public function cancel(array $p): void
    {
        Db::call('sp_purchase_invoice_cancel', [$this->uid(), $p['id']]);
        Flash::success('Bill cancelled.');
        redirect('/purchase-invoices/' . $p['id']);
    }

    private function formData(array $row, array $lines): array
    {
        $suppliers = [];
        foreach (Db::rows('sp_supplier_list', [$this->uid()]) as $s) {
            $suppliers[$s['id']] = $s['name'];
        }
        $items = [];
        if (can('Items', 'read')) {
            foreach (Db::rows('sp_item_list', [$this->uid(), null]) as $i) {
                $items[$i['id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
            }
        }
        return ['title' => 'New supplier bill', 'row' => $row, 'suppliers' => $suppliers, 'items' => $items, 'lines' => $lines ?: [['item_id' => '', 'qty' => '', 'rate' => '', 'tax_rate' => '']]];
    }
}
