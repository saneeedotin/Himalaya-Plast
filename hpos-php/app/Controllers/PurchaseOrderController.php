<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Purchase orders and the goods-receipt notes raised from them. */
final class PurchaseOrderController extends Controller
{
    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $rows = Db::rows('sp_po_list', [$this->uid()]);
        if (in_array($status, ['DRAFT', 'SUBMITTED', 'PARTIAL', 'RECEIVED', 'CLOSED'], true)) {
            $rows = array_values(array_filter($rows, static fn (array $p): bool => $p['status'] === $status));
        } else {
            $status = null;
        }
        $this->render('purchase_orders/index', ['title' => 'Purchase orders', 'orders' => $rows, 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('purchase_orders/form', $this->formData(null, ['supplier_id' => (string) ($_GET['supplier'] ?? '')], [], ['title' => 'New purchase order']));
    }

    public function store(): void
    {
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($row, $lines) {
            $id = (string) Db::callOut('sp_po_create', [$this->uid(), $this->supplier($row), $row['notes'] ?: null, json_encode($this->itemLines($lines))])[0];
            Flash::success('Purchase order created as a draft.');
            redirect('/purchase-orders/' . $id);
        }, 'purchase_orders/form', $this->formData(null, $row, $lines, ['title' => 'New purchase order']));
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_po_get', [$this->uid(), $p['id']]);
        $po = $this->must($sets[0][0] ?? null);
        $receipts = array_values(array_filter(Db::rows('sp_grn_list', [$this->uid()]), static fn (array $r): bool => $r['purchase_order_id'] === $p['id']));
        $this->render('purchase_orders/show', ['title' => $po['po_number'], 'po' => $po, 'lines' => $sets[1] ?? [], 'receipts' => $receipts]);
    }

    public function edit(array $p): void
    {
        $sets = Db::call('sp_po_get', [$this->uid(), $p['id']]);
        $po = $this->must($sets[0][0] ?? null);
        if ($po['status'] !== 'DRAFT') {
            throw new AppError('Only draft purchase orders can be edited.');
        }
        $lines = array_map(static fn (array $l): array => ['item_id' => $l['item_id'], 'qty' => trim_zeros($l['qty']), 'rate' => trim_zeros($l['rate'])], $sets[1] ?? []);
        $this->render('purchase_orders/form', $this->formData($po, ['supplier_id' => $po['supplier_id'], 'notes' => (string) $po['notes']], $lines, ['title' => 'Edit ' . $po['po_number']]));
    }

    public function update(array $p): void
    {
        $po = $this->must(Db::row('sp_po_get', [$this->uid(), $p['id']]));
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($po, $row, $lines) {
            Db::call('sp_po_update', [$this->uid(), $po['id'], $this->supplier($row), $row['notes'] ?: null, json_encode($this->itemLines($lines))]);
            Flash::success('Purchase order saved.');
            redirect('/purchase-orders/' . $po['id']);
        }, 'purchase_orders/form', $this->formData($po, $row, $lines, ['title' => 'Edit ' . $po['po_number']]));
    }

    public function destroy(array $p): void
    {
        Db::call('sp_po_delete', [$this->uid(), $p['id']]);
        Flash::success('Purchase order deleted.');
        redirect('/purchase-orders');
    }

    public function setStatus(array $p): void
    {
        $status = $this->str('status');
        if (!in_array($status, ['SUBMITTED', 'CLOSED'], true)) {
            throw new AppError('That status cannot be set here.');
        }
        Db::call('sp_po_set_status', [$this->uid(), $p['id'], $status]);
        Flash::success($status === 'SUBMITTED' ? 'Purchase order submitted to the supplier.' : 'Purchase order closed.');
        redirect('/purchase-orders/' . $p['id']);
    }

    /** One click: book every line into stock as its own batch. */
    public function receiveAll(array $p): void
    {
        Db::call('sp_po_receive', [$this->uid(), $p['id']]);
        Flash::success('Everything on the purchase order was received into stock.');
        redirect('/purchase-orders/' . $p['id']);
    }

    /** Draft goods-receipt note copied from the order lines; stock moves when it is submitted. */
    public function createReceipt(array $p): void
    {
        $id = (string) Db::callOut('sp_grn_create', [$this->uid(), $p['id'], $this->dt('receipt_date'), $this->nul('notes')])[0];
        Flash::success('Receipt note drafted. Check the quantities, then submit it to book the stock.');
        redirect('/receipts/' . $id);
    }

    private function readForm(): array
    {
        $row = ['supplier_id' => $this->str('supplier_id'), 'notes' => $this->str('notes')];
        $items = (array) ($_POST['item_id'] ?? []);
        $qtys = (array) ($_POST['qty'] ?? []);
        $rates = (array) ($_POST['rate'] ?? []);
        $lines = [];
        foreach ($items as $i => $itemId) {
            $line = ['item_id' => trim((string) $itemId), 'qty' => trim((string) ($qtys[$i] ?? '')), 'rate' => trim((string) ($rates[$i] ?? ''))];
            if ($line['item_id'] !== '' || $line['qty'] !== '' || $line['rate'] !== '') {
                $lines[] = $line;
            }
        }
        return [$row, $lines];
    }

    private function supplier(array $row): string
    {
        if ($row['supplier_id'] === '') {
            throw new AppError('Choose a supplier.');
        }
        return $row['supplier_id'];
    }

    /** @return list<array{itemId:string,qty:float,rate:float}> */
    private function itemLines(array $lines): array
    {
        if (!$lines) {
            throw new AppError('Add at least one item.');
        }
        $out = [];
        foreach ($lines as $l) {
            if ($l['item_id'] === '' || !is_numeric($l['qty']) || (float) $l['qty'] <= 0 || !is_numeric($l['rate']) || (float) $l['rate'] < 0) {
                throw new AppError('Every line needs an item, a quantity above zero and a rate.');
            }
            $out[] = ['itemId' => $l['item_id'], 'qty' => (float) $l['qty'], 'rate' => (float) $l['rate']];
        }
        return $out;
    }

    private function formData(?array $po, array $row, array $lines, array $extra): array
    {
        $suppliers = [];
        foreach (Db::rows('sp_supplier_list', [$this->uid()]) as $s) {
            if ($s['status'] === 'ACTIVE' || ($po && $s['id'] === $po['supplier_id'])) {
                $suppliers[$s['id']] = $s['name'];
            }
        }
        $items = [];
        foreach (Db::rows('sp_item_list', [$this->uid(), null]) as $i) {
            if (in_array($i['category'], ['RAW_MATERIAL', 'PACKAGING'], true)) {
                $items[$i['id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
            }
        }
        return $extra + ['po' => $po, 'row' => $row, 'suppliers' => $suppliers, 'items' => $items, 'lines' => $lines ?: [['item_id' => '', 'qty' => '', 'rate' => '']]];
    }
}
