<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/**
 * Buying advice: which raw materials to order, how much, and from whom, worked out from real usage, stock, what is on
 * order and the supplier lead time. HPOS suggests; a person ticks the lines and confirms the draft purchase order.
 */
final class BuyingAdviceController extends Controller
{
    public const STATUS = ['ORDER_NOW' => ['Order now', 'fail'], 'ORDER_SOON' => ['Order soon', 'warn'], 'OK' => ['OK', 'ok']];

    public function index(): void
    {
        $sets = Db::call('sp_buying_advice', [$this->uid()]);
        $rows = $sets[0] ?? [];
        $suppliers = [];
        foreach ($sets[1] ?? [] as $s) {
            $suppliers[$s['id']] = $s['name'];
        }
        $show = (string) ($_GET['show'] ?? 'act');
        $count = ['ORDER_NOW' => 0, 'ORDER_SOON' => 0, 'OK' => 0];
        foreach ($rows as $r) {
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
        }
        if ($show !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'OK'));
        }
        // group by preferred supplier (or none), each group is one purchase order
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['preferred_supplier_id'] ?: ''][] = $r;
        }
        $this->render('buying_advice/index', ['title' => 'Buying advice', 'groups' => $groups, 'suppliers' => $suppliers, 'count' => $count, 'show' => $show === 'all' ? 'all' : 'act']);
    }

    /** Draft purchase order from the ticked lines: item[] holds the ticked item ids, qty[<id>] the quantity for each. */
    public function createPo(): void
    {
        $supplier = $this->str('supplier_id');
        $ticked = $this->strList('item');
        $qty = (array) ($_POST['qty'] ?? []);
        $lines = [];
        foreach ($ticked as $id) {
            $q = trim((string) ($qty[$id] ?? ''));
            if (!is_numeric($q) || (float) $q <= 0) {
                throw new AppError('Every ticked material needs a quantity above zero.');
            }
            $lines[] = ['itemId' => $id, 'qty' => (float) $q];
        }
        $po = (string) Db::callOut('sp_buying_create_po', [$this->uid(), $supplier ?: null, json_encode($lines)])[0];
        Flash::success('Draft purchase order created. Check the rates and submit it when you are ready.');
        redirect('/purchase-orders/' . $po);
    }
}
