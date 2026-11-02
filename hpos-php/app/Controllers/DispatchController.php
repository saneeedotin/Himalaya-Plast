<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Delivery notes, carton labels and the dock-gate scan. */
final class DispatchController extends Controller
{
    public function index(): void
    {
        $this->render('dispatch/index', ['title' => 'Dispatch', 'notes' => Db::rows('sp_delivery_note_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('dispatch/form', $this->formData(['order_id' => (string) ($_GET['order'] ?? '')], []));
    }

    /** Pack finished batches into serial-numbered cartons on a new delivery note. */
    public function store(): void
    {
        $row = ['order_id' => $this->str('order_id')];
        $batches = (array) ($_POST['batch_id'] ?? []);
        $counts = (array) ($_POST['carton_count'] ?? []);
        $qtys = (array) ($_POST['qty_per_carton'] ?? []);
        $lines = [];
        foreach ($batches as $i => $b) {
            $line = ['batch_id' => trim((string) $b), 'carton_count' => trim((string) ($counts[$i] ?? '')), 'qty_per_carton' => trim((string) ($qtys[$i] ?? ''))];
            if ($line['batch_id'] !== '' || $line['carton_count'] !== '' || $line['qty_per_carton'] !== '') {
                $lines[] = $line;
            }
        }
        $this->saving(function () use ($row, $lines) {
            $order = $this->order($row['order_id']);
            if (!$lines) {
                throw new AppError('Add at least one batch to pack.');
            }
            $items = [];
            foreach ($lines as $l) {
                if ($l['batch_id'] === '' || !ctype_digit($l['carton_count']) || (int) $l['carton_count'] < 1 || (int) $l['carton_count'] > 2000
                    || !is_numeric($l['qty_per_carton']) || (float) $l['qty_per_carton'] <= 0) {
                    throw new AppError('Every line needs a batch, 1 to 2000 cartons and a quantity per carton above zero.');
                }
                $items[] = ['batchId' => $l['batch_id'], 'cartonCount' => (int) $l['carton_count'], 'qtyPerCarton' => (float) $l['qty_per_carton']];
            }
            $id = (string) Db::callOut('sp_delivery_note_create_and_pack', [$this->uid(), $order['id'], $order['customer_name'], json_encode($items)])[0];
            Flash::success('Delivery note created and cartons serialised. Print the labels and stick them on.');
            redirect('/dispatch/' . $id);
        }, 'dispatch/form', $this->formData($row, $lines));
    }

    public function show(array $p): void
    {
        [$dn, $cartons] = $this->load($p['id']);
        $this->render('dispatch/show', ['title' => $dn['dn_number'], 'dn' => $dn, 'cartons' => $cartons]);
    }

    public function labels(array $p): void
    {
        [$dn, $cartons] = $this->load($p['id']);
        \App\Core\View::render('dispatch/labels', ['title' => 'Labels ' . $dn['dn_number'], 'dn' => $dn, 'cartons' => $cartons], 'bare');
    }

    public function note(array $p): void
    {
        [$dn, $cartons] = $this->load($p['id']);
        \App\Core\View::render('dispatch/note', ['title' => $dn['dn_number'], 'dn' => $dn, 'cartons' => $cartons], 'bare');
    }

    /** Add one carton to the order's delivery note. */
    public function addCarton(array $p): void
    {
        [$dn] = $this->load($p['id']);
        $qty = $this->str('quantity');
        if (!is_numeric($qty) || (float) $qty <= 0) {
            throw new AppError('The carton quantity must be above zero.');
        }
        $code = strtoupper($this->str('carton_code'));
        if ($code !== '' && !\App\Core\Barcode::canEncode($code)) {
            throw new AppError('A carton code can use letters, digits, spaces, dash and dot only.');
        }
        Db::row('sp_carton_quick_serialize', [$this->uid(), $dn['sales_order_id'], $qty, $code ?: null]);
        Flash::success('Carton added.');
        redirect('/dispatch/' . $p['id']);
    }

    public function transport(array $p): void
    {
        [$dn] = $this->load($p['id']);
        Db::row('sp_dispatch_gate_update', [$this->uid(), $dn['sales_order_id'], $this->nul('transporter'), $this->nul('vehicle'), $this->nul('lr'), null, null]);
        Flash::success('Transport details saved.');
        redirect('/dispatch/' . $p['id']);
    }

    /**
     * Mark cartons as loaded on the vehicle, by ticking them on the delivery note (no scanner needed). Each carton goes
     * through the same procedure as a scan, so stock leaves, and the note and order become Dispatched with the last one.
     */
    public function loadCartons(array $p): void
    {
        [$dn, $cartons] = $this->load($p['id']);
        $pick = $this->str('all') === '1' ? null : array_map('strval', (array) ($_POST['cartons'] ?? []));
        $todo = [];
        foreach ($cartons as $c) {
            if ((int) $c['scanned'] === 0 && ($pick === null || in_array($c['carton_code'], $pick, true))) {
                $todo[] = $c['carton_code'];
            }
        }
        if (!$todo) {
            throw new AppError('Tick at least one carton that is not loaded yet.');
        }
        $done = 0;
        foreach ($todo as $code) {
            try {
                Db::row('sp_dispatch_scan', [$this->uid(), $code, 0, null]);
                $done++;
            } catch (AppError $e) {
                if ($e->isForbidden()) {
                    throw $e;
                }
                Flash::error(($done > 0 ? $done . ' loaded. ' : '') . 'Stopped at ' . $code . ': ' . $e->getMessage());
                redirect('/dispatch/' . $dn['id']);
            }
        }
        Flash::success($done . ' carton' . ($done === 1 ? '' : 's') . ' marked as loaded.');
        redirect('/dispatch/' . $dn['id']);
    }

    public function scanForm(): void
    {
        $this->render('dispatch/scan', ['title' => 'Dispatch scan', 'result' => null, 'refused' => null, 'code' => '']);
    }

    public function scan(): void
    {
        $code = strtoupper($this->str('carton_code'));
        if ($code === '') {
            throw new AppError('Scan or type a carton code.');
        }
        $override = $this->str('override') === '1';
        if ($override && $this->str('override_note') === '') {
            throw new AppError('Give a reason for scanning this carton again.');
        }
        try {
            $res = Db::row('sp_dispatch_scan', [$this->uid(), $code, $override ? 1 : 0, $override ? $this->str('override_note') : null]);
            $this->render('dispatch/scan', ['title' => 'Dispatch scan', 'result' => $res, 'refused' => null, 'code' => '']);
        } catch (AppError $e) {
            if ($e->isForbidden()) {
                throw $e;
            }
            http_response_code(409);
            $this->render('dispatch/scan', ['title' => 'Dispatch scan', 'result' => null, 'refused' => $e->getMessage(), 'code' => $code]);
        }
    }

    /** @return array{0:array,1:list<array>} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_delivery_note_get', [$this->uid(), $id]);
        return [$this->must($sets[0][0] ?? null), $sets[1] ?? []];
    }

    /** @return array{id:string,customer_name:string} */
    private function order(string $id): array
    {
        foreach (Db::rows('sp_order_list', [$this->uid(), null]) as $o) {
            if ($o['id'] === $id) {
                return ['id' => $o['id'], 'customer_name' => $o['customer_name']];
            }
        }
        throw new AppError('Choose the sales order.');
    }

    private function formData(array $row, array $lines): array
    {
        $orders = [];
        foreach (Db::rows('sp_order_list', [$this->uid(), null]) as $o) {
            if (in_array($o['status'], ['CONFIRMED', 'APPROVED', 'IN_PRODUCTION', 'READY_TO_DISPATCH', 'DISPATCH_PREP'], true)) {
                $orders[$o['id']] = $o['order_number'] . ' ' . $o['customer_name'];
            }
        }
        $batches = [];
        foreach (Db::rows('sp_batch_list', [$this->uid(), 'EXTRUSION']) as $b) {
            $batches[$b['id']] = $b['batch_number'] . ' - ' . $b['item_name'] . ' (' . trim_zeros($b['quantity']) . ' ' . $b['uom'] . ')';
        }
        return ['title' => 'Pack and create delivery note', 'row' => $row, 'orders' => $orders, 'batches' => $batches, 'lines' => $lines ?: [['batch_id' => '', 'carton_count' => '', 'qty_per_carton' => '']]];
    }
}
