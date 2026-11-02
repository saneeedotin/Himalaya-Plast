<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Sales cockpit: orders, the material check, the confirm gate, shortage purchase orders. */
final class OrderController extends Controller
{
    public const APPROVAL = ['WHATSAPP' => 'WhatsApp', 'EMAIL' => 'Email', 'PHONE' => 'Phone', 'VERBAL' => 'Verbal'];

    /** Statuses an order can be edited or deleted in. */
    private const OPEN = ['DRAFT', 'PROFORMA_SENT'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $statuses = ['DRAFT', 'PROFORMA_SENT', 'CONFIRMED', 'IN_PRODUCTION', 'READY_TO_DISPATCH', 'DISPATCH_PREP', 'DISPATCHED', 'COMPLETED', 'CANCELLED'];
        $status = in_array($status, $statuses, true) ? $status : null;
        $this->render('orders/index', [
            'title'    => 'Sales orders',
            'orders'   => Db::rows('sp_order_list', [$this->uid(), $status]),
            'status'   => $status,
            'statuses' => $statuses,
        ]);
    }

    public function create(): void
    {
        $this->render('orders/form', $this->formData(null, ['approval_method' => 'WHATSAPP'], [], ['title' => 'New order']));
    }

    public function store(): void
    {
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($row, $lines) {
            $customer = $this->customer($row['customer_id']);
            $id = (string) Db::callOut('sp_sales_order_create', [
                $this->uid(), $customer['id'], $customer['name'], $customer['gstin'], $this->deliveryDate($row), $row['approval_method'],
                $row['notes'] ?: null, json_encode($this->itemLines($lines)),
            ])[0];
            Flash::success('Order created. Check the materials, then confirm it.');
            redirect('/orders/' . $id);
        }, 'orders/form', $this->formData(null, $row, $lines, ['title' => 'New order']));
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_order_detail', [$this->uid(), $p['id']]);
        $order = $this->must($sets[0][0] ?? null);
        $material = $sets[2] ?? [];
        // what is already on order, so a shortage can say "covered by a purchase order" (the hard gate is unchanged)
        $incoming = [];
        if (array_filter($material, static fn (array $m): bool => $m['status'] === 'DEFICIT')) {
            foreach (Db::rows('sp_incoming_by_item', [$this->uid()]) as $i) {
                $incoming[$i['item_id']] = $i;
            }
        }
        $this->render('orders/show', [
            'incoming'     => $incoming,
            'projection'   => Db::row('sp_order_projection', [$this->uid(), $p['id']]),
            'title'        => $order['order_number'],
            'order'        => $order,
            'lines'        => $sets[1] ?? [],
            'material'     => $material,
            'hasDeficit'   => (bool) array_filter($material, static fn (array $m): bool => $m['status'] === 'DEFICIT'),
            'workOrders'   => $sets[3] ?? [],
            'jobCards'     => $sets[4] ?? [],
            'reservations' => $sets[5] ?? [],
            'deliveries'   => $sets[6] ?? [],
            'cartons'      => $sets[7] ?? [],
            'qc'           => $sets[8] ?? [],
            'invoices'     => $sets[9] ?? [],
            'editable'     => in_array($order['status'], self::OPEN, true),
        ]);
    }

    public function edit(array $p): void
    {
        $sets = Db::call('sp_order_detail', [$this->uid(), $p['id']]);
        $order = $this->must($sets[0][0] ?? null);
        if (!in_array($order['status'], self::OPEN, true)) {
            throw new AppError('Confirmed orders cannot be edited (stock is already reserved).');
        }
        $row = [
            'customer_id'     => $order['customer_id'],
            'delivery_date'   => substr((string) $order['delivery_date'], 0, 10),
            'approval_method' => $order['approval_method'],
            'notes'           => (string) ($order['notes'] ?? ''),
        ];
        $lines = array_map(static fn (array $l): array => ['item_id' => $l['item_id'], 'qty' => trim_zeros($l['qty']), 'rate' => trim_zeros($l['rate'])], $sets[1] ?? []);
        $this->render('orders/form', $this->formData($order, $row, $lines, ['title' => 'Edit ' . $order['order_number']]));
    }

    public function update(array $p): void
    {
        $order = $this->must(Db::row('sp_order_detail', [$this->uid(), $p['id']]));
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($order, $row, $lines) {
            $customer = $this->customer($row['customer_id']);
            Db::call('sp_sales_order_update', [
                $this->uid(), $order['id'], $customer['id'], $customer['name'], $customer['gstin'], $this->deliveryDate($row),
                $row['approval_method'], $row['notes'] ?: null, json_encode($this->itemLines($lines)),
            ]);
            Flash::success('Order saved.');
            redirect('/orders/' . $order['id']);
        }, 'orders/form', $this->formData($order, $row, $lines, ['title' => 'Edit ' . $order['order_number']]));
    }

    public function destroy(array $p): void
    {
        Db::call('sp_sales_order_delete', [$this->uid(), $p['id']]);
        Flash::success('Order deleted.');
        redirect('/orders');
    }

    /** The hard gate: the database refuses unless 100% of the raw material is available. */
    public function confirm(array $p): void
    {
        $method = $this->str('approval_method');
        if (!isset(self::APPROVAL[$method])) {
            throw new AppError('Choose how the customer approved the order.');
        }
        Db::call('sp_sales_order_confirm', [$this->uid(), $p['id'], $method, $this->nul('notes')]);
        Flash::success('Order confirmed. Materials are reserved and work orders created.');
        redirect('/orders/' . $p['id']);
    }

    public function setStatus(array $p): void
    {
        $status = $this->str('status');
        if (!in_array($status, ['PROFORMA_SENT', 'CANCELLED'], true)) {
            throw new AppError('That status cannot be set here.');
        }
        Db::call('sp_sales_order_set_status', [$this->uid(), $p['id'], $status, $this->nul('proforma_ref'), null]);
        Flash::success($status === 'CANCELLED' ? 'Order cancelled and its reservations released.' : 'Marked as proforma sent.');
        redirect('/orders/' . $p['id']);
    }

    /** Draft purchase order for whatever raw material the order is short of. */
    public function shortagePo(array $p): void
    {
        $poId = (string) Db::callOut('sp_po_create_for_shortage', [$this->uid(), $p['id']])[0];
        $po = can('PurchaseOrders', 'read') ? Db::row('sp_po_get', [$this->uid(), $poId]) : null;
        Flash::success('Draft purchase order ' . ($po['po_number'] ?? '') . ' created for the shortage.');
        redirect('/orders/' . $p['id']);
    }

    private function readForm(): array
    {
        $row = [
            'customer_id'     => $this->str('customer_id'),
            'delivery_date'   => $this->str('delivery_date'),
            'approval_method' => $this->str('approval_method') ?: 'WHATSAPP',
            'notes'           => $this->str('notes'),
        ];
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

    /** @return array{id:string,name:string,gstin:?string} */
    private function customer(string $id): array
    {
        if ($id === '') {
            throw new AppError('Choose a customer.');
        }
        foreach (Db::rows('sp_customer_list', [$this->uid()]) as $c) {
            if ($c['id'] === $id) {
                return ['id' => $c['id'], 'name' => $c['name'], 'gstin' => $c['gstin']];
            }
        }
        throw new AppError('That customer does not exist.');
    }

    private function deliveryDate(array $row): string
    {
        $t = $row['delivery_date'] === '' ? false : strtotime($row['delivery_date']);
        if ($t === false) {
            throw new AppError('Enter the delivery date.');
        }
        if (!isset(self::APPROVAL[$row['approval_method']])) {
            throw new AppError('Choose how the customer approved the order.');
        }
        return date('Y-m-d 00:00:00', $t);
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

    private function formData(?array $order, array $row, array $lines, array $extra): array
    {
        $customers = [];
        foreach (Db::rows('sp_customer_list', [$this->uid()]) as $c) {
            $customers[$c['id']] = $c['name'];
        }
        $items = [];
        foreach (Db::rows('sp_item_list', [$this->uid(), 'FINISHED_GOODS']) as $i) {
            $items[$i['id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
        }
        return $extra + [
            'order' => $order, 'row' => $row, 'customers' => $customers, 'items' => $items,
            'lines' => $lines ?: [['item_id' => '', 'qty' => '', 'rate' => '']],
        ];
    }
}
