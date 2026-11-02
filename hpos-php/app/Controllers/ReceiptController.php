<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;

/** Goods-receipt notes (GRN). Submitting one books the stock and closes its purchase order. */
final class ReceiptController extends Controller
{
    public function index(): void
    {
        $this->render('receipts/index', ['title' => 'Goods receipts', 'receipts' => Db::rows('sp_grn_list', [$this->uid()])]);
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_grn_get', [$this->uid(), $p['id']]);
        $grn = $this->must($sets[0][0] ?? null);
        $this->render('receipts/show', ['title' => $grn['receipt_number'], 'grn' => $grn, 'lines' => $sets[1] ?? []]);
    }

    public function submit(array $p): void
    {
        Db::call('sp_grn_submit', [$this->uid(), $p['id']]);
        Flash::success('Receipt submitted. The stock is booked and the purchase order is closed.');
        redirect('/receipts/' . $p['id']);
    }
}
