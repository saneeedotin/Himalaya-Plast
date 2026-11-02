<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;
use App\Core\View;

/** Sales invoices: draft from an order, edit lines, submit, e-invoice details, print. */
final class InvoiceController extends Controller
{
    public const STATUSES = ['DRAFT' => 'Draft', 'UNPAID' => 'Unpaid', 'PARTIAL' => 'Part paid', 'PAID' => 'Paid', 'CANCELLED' => 'Cancelled'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = isset(self::STATUSES[$status]) ? $status : null;
        $this->render('invoices/index', ['title' => 'Sales invoices', 'invoices' => Db::rows('sp_sales_invoice_list', [$this->uid(), $status]), 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('invoices/form', $this->formData(['order_id' => (string) ($_GET['order'] ?? '')]));
    }

    public function store(): void
    {
        $row = ['order_id' => $this->str('order_id'), 'customer_id' => $this->str('customer_id'), 'invoice_date' => $this->str('invoice_date'), 'due_date' => $this->str('due_date')];
        $this->saving(function () use ($row) {
            if ($row['order_id'] === '' && $row['customer_id'] === '') {
                throw new AppError('Choose a sales order or a customer.');
            }
            $id = (string) Db::callOut('sp_sales_invoice_create', [
                $this->uid(), $row['customer_id'] ?: null, $row['order_id'] ?: null, null, $this->dt('invoice_date'), $this->dt('due_date'),
            ])[0];
            Flash::success('Draft invoice created' . ($row['order_id'] !== '' ? ' from the order lines.' : '. Add the lines below.'));
            redirect('/invoices/' . $id);
        }, 'invoices/form', $this->formData($row));
    }

    public function show(array $p): void
    {
        [$inv, $lines, $payments] = $this->load($p['id']);
        $this->render('invoices/show', ['title' => $inv['invoice_number'], 'inv' => $inv, 'lines' => $lines, 'payments' => $payments, 'items' => $this->itemOptions($inv['status'])]);
    }

    public function setLines(array $p): void
    {
        $lines = [];
        $ids = (array) ($_POST['item_id'] ?? []);
        $qtys = (array) ($_POST['qty'] ?? []);
        $rates = (array) ($_POST['rate'] ?? []);
        $taxes = (array) ($_POST['tax_rate'] ?? []);
        foreach ($ids as $i => $id) {
            $l = ['itemId' => trim((string) $id), 'qty' => trim((string) ($qtys[$i] ?? '')), 'rate' => trim((string) ($rates[$i] ?? '')), 'taxRate' => trim((string) ($taxes[$i] ?? ''))];
            if ($l['itemId'] === '' && $l['qty'] === '' && $l['rate'] === '') {
                continue;
            }
            if ($l['itemId'] === '' || !is_numeric($l['qty']) || (float) $l['qty'] <= 0 || !is_numeric($l['rate']) || (float) $l['rate'] < 0
                || ($l['taxRate'] !== '' && (!is_numeric($l['taxRate']) || (float) $l['taxRate'] < 0 || (float) $l['taxRate'] > 100))) {
                throw new AppError('Every line needs an item, a quantity above zero, a rate and a tax rate from 0 to 100.');
            }
            $lines[] = ['itemId' => $l['itemId'], 'qty' => (float) $l['qty'], 'rate' => (float) $l['rate'], 'taxRate' => $l['taxRate'] === '' ? 18.0 : (float) $l['taxRate']];
        }
        Db::call('sp_sales_invoice_set_items', [$this->uid(), $p['id'], json_encode($lines)]);
        Flash::success('Lines saved. Totals and GST were recalculated.');
        redirect('/invoices/' . $p['id']);
    }

    public function submit(array $p): void
    {
        Db::call('sp_sales_invoice_submit', [$this->uid(), $p['id'], $this->nul('irn'), $this->nul('eway')]);
        Flash::success('Invoice posted. It can no longer be edited.');
        redirect('/invoices/' . $p['id']);
    }

    public function einvoice(array $p): void
    {
        Db::call('sp_sales_invoice_set_einvoice', [$this->uid(), $p['id'], $this->nul('irn'), $this->nul('qr'), $this->nul('eway')]);
        Flash::success('E-invoice details saved.');
        redirect('/invoices/' . $p['id']);
    }

    public function cancel(array $p): void
    {
        Db::call('sp_sales_invoice_cancel', [$this->uid(), $p['id']]);
        Flash::success('Invoice cancelled.');
        redirect('/invoices/' . $p['id']);
    }

    public function print(array $p): void
    {
        [$inv, $lines] = $this->load($p['id']);
        $settings = [];
        foreach (Db::rows('sp_settings_list', [$this->uid()]) as $s) {
            $settings[$s['setting_key']] = $s['setting_value'];
        }
        View::render('invoices/print', ['title' => $inv['invoice_number'], 'inv' => $inv, 'lines' => $lines, 'settings' => $settings], 'bare');
    }

    /** @return array{0:array,1:list<array>,2:list<array>} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_sales_invoice_get', [$this->uid(), $id]);
        return [$this->must($sets[0][0] ?? null), $sets[1] ?? [], $sets[2] ?? []];
    }

    /** @return array<string,string> */
    private function itemOptions(string $status): array
    {
        if ($status !== 'DRAFT' || !can('Items', 'read')) {
            return [];
        }
        $out = [];
        foreach (Db::rows('sp_item_list', [$this->uid(), 'FINISHED_GOODS']) as $i) {
            $out[$i['id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
        }
        return $out;
    }

    private function formData(array $row): array
    {
        $orders = [];
        foreach (Db::rows('sp_order_list', [$this->uid(), null]) as $o) {
            if (in_array($o['status'], ['DISPATCHED', 'COMPLETED', 'DISPATCH_PREP', 'READY_TO_DISPATCH', 'IN_PRODUCTION'], true) && $o['customer_id']) {
                $orders[$o['id']] = $o['order_number'] . ' ' . $o['customer_name'] . ' (' . fmt_num($o['total_amount']) . ')';
            }
        }
        $customers = [];
        foreach (Db::rows('sp_customer_list', [$this->uid()]) as $c) {
            $customers[$c['id']] = $c['name'];
        }
        return ['title' => 'New sales invoice', 'row' => $row, 'orders' => $orders, 'customers' => $customers];
    }
}
