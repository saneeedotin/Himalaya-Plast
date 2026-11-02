<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Money in and out. A payment is drafted, then submitted; submitting settles the invoice. */
final class PaymentController extends Controller
{
    public const MODES = ['BANK_TRANSFER' => 'Bank transfer', 'UPI' => 'UPI', 'CHEQUE' => 'Cheque', 'CASH' => 'Cash'];

    public function index(): void
    {
        $this->render('payments/index', ['title' => 'Payments', 'payments' => Db::rows('sp_payment_list', [$this->uid()])]);
    }

    /** ?sales_invoice=ID or ?purchase_invoice=ID fills in the party and amount from the invoice. */
    public function create(): void
    {
        $row = ['party_type' => 'CUSTOMER', 'mode' => 'BANK_TRANSFER', 'payment_date' => date('Y-m-d')];
        if (($_GET['sales_invoice'] ?? '') !== '') {
            $inv = Db::row('sp_sales_invoice_get', [$this->uid(), (string) $_GET['sales_invoice']]);
            if ($inv) {
                $row = ['party_type' => 'CUSTOMER', 'party_id' => $inv['customer_id'], 'sales_invoice_id' => $inv['id'], 'amount' => trim_zeros($inv['outstanding'])] + $row;
            }
        } elseif (($_GET['purchase_invoice'] ?? '') !== '') {
            $inv = Db::row('sp_purchase_invoice_get', [$this->uid(), (string) $_GET['purchase_invoice']]);
            if ($inv) {
                $row = ['party_type' => 'SUPPLIER', 'party_id' => $inv['supplier_id'], 'purchase_invoice_id' => $inv['id'], 'amount' => trim_zeros($inv['outstanding'])] + $row;
            }
        }
        $this->render('payments/form', $this->formData($row));
    }

    public function store(): void
    {
        $row = [
            'party_type' => $this->str('party_type'), 'party_id' => $this->str('party_id'), 'mode' => $this->str('mode'),
            'reference' => $this->str('reference'), 'amount' => $this->str('amount'), 'payment_date' => $this->str('payment_date'),
            'sales_invoice_id' => $this->str('sales_invoice_id'), 'purchase_invoice_id' => $this->str('purchase_invoice_id'),
            'post_now' => $this->str('post_now'), 'account_id' => $this->str('account_id'),
        ];
        // the optional "settle this invoice" list on the standalone form: S:<id> for a sales invoice, P:<id> for a bill
        $ref = $this->str('invoice_ref');
        if ($ref !== '' && $row['sales_invoice_id'] === '' && $row['purchase_invoice_id'] === '') {
            if (str_starts_with($ref, 'S:') && $row['party_type'] === 'CUSTOMER') {
                $row['sales_invoice_id'] = substr($ref, 2);
            } elseif (str_starts_with($ref, 'P:') && $row['party_type'] === 'SUPPLIER') {
                $row['purchase_invoice_id'] = substr($ref, 2);
            }
        }
        $this->saving(function () use ($row) {
            if (!in_array($row['party_type'], ['CUSTOMER', 'SUPPLIER'], true)) {
                throw new AppError('Choose whether this is from a customer or to a supplier.');
            }
            if ($row['party_id'] === '') {
                throw new AppError('Choose who the payment is ' . ($row['party_type'] === 'CUSTOMER' ? 'from' : 'to') . '.');
            }
            if (!isset(self::MODES[$row['mode']])) {
                throw new AppError('Choose the mode of payment.');
            }
            if (!is_numeric($row['amount']) || (float) $row['amount'] <= 0) {
                throw new AppError('The amount must be above zero.');
            }
            $id = (string) Db::callOut('sp_payment_create', [
                $this->uid(), $row['party_type'], $row['party_id'], $row['mode'], $row['reference'] ?: null, $row['amount'],
                $row['sales_invoice_id'] ?: null, $row['purchase_invoice_id'] ?: null, $this->dt('payment_date'),
            ])[0];
            // which cash or bank account it goes through; left empty, the mode decides (cash, cheque, or the bank account)
            if ($row['account_id'] !== '') {
                Db::call('sp_payment_set_account', [$this->uid(), $id, $row['account_id']]);
            }
            if ($row['post_now'] === '1') {
                try {
                    Db::call('sp_payment_submit', [$this->uid(), $id]);
                } catch (AppError $e) {
                    // The draft stays so nothing is lost; say why it was not posted.
                    Flash::error('Saved as a draft but not posted: ' . $e->getMessage());
                    redirect('/payments');
                }
                Flash::success('Payment recorded and posted.');
            } else {
                Flash::success('Payment saved as a draft. Post it to settle the invoice.');
            }
            redirect('/payments');
        }, 'payments/form', $this->formData($row));
    }

    public function submit(array $p): void
    {
        Db::call('sp_payment_submit', [$this->uid(), $p['id']]);
        Flash::success('Payment posted.');
        redirect('/payments');
    }

    public function cancel(array $p): void
    {
        Db::call('sp_payment_cancel', [$this->uid(), $p['id']]);
        Flash::success('Payment cancelled. The invoice balance was restored.');
        redirect('/payments');
    }

    private function formData(array $row): array
    {
        $customers = $suppliers = [];
        foreach (Db::rows('sp_customer_list', [$this->uid()]) as $c) {
            $customers[$c['id']] = $c['name'];
        }
        foreach (Db::rows('sp_supplier_list', [$this->uid()]) as $s) {
            $suppliers[$s['id']] = $s['name'];
        }
        // unpaid and part-paid invoices, so a payment on the standalone form can be tied to one
        $open = [];
        foreach (Db::rows('sp_sales_invoice_list', [$this->uid(), null]) as $i) {
            if (in_array($i['status'], ['UNPAID', 'PARTIAL'], true)) {
                $open[] = ['ref' => 'S:' . $i['id'], 'party' => $i['customer_id'], 'number' => $i['invoice_number'], 'outstanding' => trim_zeros($i['outstanding'])];
            }
        }
        foreach (Db::rows('sp_purchase_invoice_list', [$this->uid(), null]) as $i) {
            if (in_array($i['status'], ['UNPAID', 'PARTIAL'], true)) {
                $open[] = ['ref' => 'P:' . $i['id'], 'party' => $i['supplier_id'], 'number' => $i['invoice_number'], 'outstanding' => trim_zeros($i['outstanding'])];
            }
        }
        $accounts = [];
        foreach (Db::rows('sp_gl_account_pick', [$this->uid(), 'CASHBANK']) as $a) {
            $accounts[$a['id']] = $a['name'];
        }
        return ['title' => 'Record a payment', 'row' => $row, 'customers' => $customers, 'suppliers' => $suppliers, 'openInvoices' => $open, 'cashAccounts' => $accounts];
    }
}
