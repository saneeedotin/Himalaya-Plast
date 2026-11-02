<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/**
 * Receiving raw material and the mixing check. Neither needs a barcode: material is received by item, bags and weight,
 * and the mixing check picks the lots from a list. A barcode scanner still works (it just types a lot reference).
 */
final class ScanController extends Controller
{
    public function receiveForm(): void
    {
        $this->render('scan/receive', $this->receiveData(['item_code' => (string) ($_GET['item'] ?? '')]));
    }

    /**
     * Receive a lot of raw material: a number of bags of one weight each. The lot reference (the supplier's lot or bill
     * number, or a barcode if the bag has one) is optional; without it the lot gets the next serial number.
     */
    public function receive(): void
    {
        $code = $this->str('item_code');
        $lot = $this->str('lot') ?: $this->str('barcode');
        $weight = $this->str('qty');
        $bags = $this->str('bags') === '' ? '1' : $this->str('bags');
        if ($code === '') {
            throw new AppError('Choose the raw material.');
        }
        if (!ctype_digit($bags) || (int) $bags < 1 || (int) $bags > 1000) {
            throw new AppError('The number of bags is a whole number from 1 to 1,000.');
        }
        if (!is_numeric($weight) || (float) $weight <= 0) {
            throw new AppError('Enter the weight of one bag above zero.');
        }
        if (mb_strlen($lot) > 60) {
            throw new AppError('The lot reference can be up to 60 characters.');
        }
        $total = (string) round((int) $bags * (float) $weight, 3);
        $res = Db::row('sp_stock_receive_rm_bag', [$this->uid(), $lot, $code, $total]);
        Flash::success('Received ' . trim_zeros($res['qty'] ?? $total) . ' of ' . ($res['material'] ?? $code) . ' as lot ' . ($res['batch_number'] ?? $lot) . '.');
        redirect('/stock/receive?item=' . rawurlencode($code));
    }

    public function mixingForm(): void
    {
        $this->render('scan/mixing', $this->mixingData(null, ['job_card_id' => (string) ($_GET['job'] ?? '')]));
    }

    public function mixing(): void
    {
        $row = ['job_card_id' => $this->str('job_card_id'), 'barcode_1' => $this->str('barcode_1'), 'barcode_2' => $this->str('barcode_2')];
        if ($row['job_card_id'] === '') {
            throw new AppError('Choose the job card.');
        }
        if ($row['barcode_1'] === '' || $row['barcode_2'] === '') {
            throw new AppError('Choose both lots.');
        }
        $res = Db::row('sp_material_verify_mixing', [$this->uid(), $row['job_card_id'], $row['barcode_1'], $row['barcode_2']]);
        http_response_code(200);
        $this->render('scan/mixing', $this->mixingData($res, ['job_card_id' => $row['job_card_id']]));
    }

    private function receiveData(array $row): array
    {
        $items = [];
        foreach (Db::rows('sp_item_list', [$this->uid(), 'RAW_MATERIAL']) as $i) {
            $items[$i['code']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
        }
        return ['title' => 'Receive material', 'row' => $row, 'items' => $items];
    }

    private function mixingData(?array $result, array $row): array
    {
        $cards = [];
        foreach (Db::rows('sp_shop_floor', [$this->uid()]) as $c) {
            $cards[$c['job_card_id']] = $c['workstation_code'] . ' - ' . $c['work_order_number'] . ' ' . $c['fg_name'];
        }
        $lots = [];
        foreach (Db::rows('sp_mixing_lot_pick', [$this->uid()]) as $l) {
            $lots[$l['batch_number']] = $l['item_code'] . ' ' . $l['item_name'] . ' · ' . $l['batch_number'] . ' (' . trim_zeros($l['quantity']) . ' ' . $l['uom'] . ', ' . substr((string) $l['created_at'], 0, 10) . ')';
        }
        return ['title' => 'Mixing check', 'row' => $row, 'cards' => $cards, 'result' => $result, 'lots' => $lots];
    }
}
