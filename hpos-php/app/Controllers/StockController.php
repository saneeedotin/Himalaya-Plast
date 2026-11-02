<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;

/** Stock position, the stock ledger and batches. */
final class StockController extends Controller
{
    public const TIERS = ['RAW_MATERIAL' => 'Raw material', 'FINISHED_GOODS' => 'Finished goods', 'PACKAGING' => 'Packaging', 'SCRAP' => 'Scrap'];

    public function index(): void
    {
        $tier = (string) ($_GET['tier'] ?? '');
        $tier = isset(self::TIERS[$tier]) ? $tier : null;
        $low = ($_GET['low'] ?? '') === '1';
        $rows = Db::rows('sp_inventory_status', [$this->uid()]);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => ($tier === null || $r['tier'] === $tier) && (!$low || (int) $r['reorder_alert'] === 1)));
        $atMachines = [];
        foreach (Db::rows('sp_stock_locations', [$this->uid()]) as $l) {
            $atMachines[$l['item_id']] = (float) $l['at_machines'];
        }
        $this->render('stock/index', ['title' => 'Stock', 'rows' => $rows, 'tier' => $tier, 'low' => $low, 'atMachines' => $atMachines]);
    }

    public function ledger(): void
    {
        $item = (string) ($_GET['item'] ?? '');
        $item = preg_match('/^[0-9a-f-]{36}$/i', $item) ? $item : null;
        $limit = (int) ($_GET['limit'] ?? 100);
        $limit = in_array($limit, [50, 100, 500], true) ? $limit : 100;
        $items = [];
        foreach (Db::rows('sp_inventory_status', [$this->uid()]) as $i) {
            $items[$i['item_id']] = $i['code'] . ' ' . $i['name'];
        }
        $this->render('stock/ledger', ['title' => 'Stock ledger', 'rows' => Db::rows('sp_stock_ledger', [$this->uid(), $item, $limit]), 'items' => $items, 'item' => $item, 'limit' => $limit]);
    }

    public const REASONS = ['OPENING' => 'Opening stock', 'COUNT' => 'Stock count correction', 'DAMAGE' => 'Damage or loss', 'OTHER' => 'Other (say why in the note)'];

    /** Opening stock, counted corrections, damage and loss: stock changes that are not a purchase, production or dispatch. */
    public function adjustForm(): void
    {
        $this->renderAdjust(['direction' => ($_GET['direction'] ?? '') === 'OUT' ? 'OUT' : 'IN', 'item_id' => (string) ($_GET['item'] ?? ''), 'reason' => 'OPENING']);
    }

    public function adjust(): void
    {
        $row = ['item_id' => $this->str('item_id'), 'direction' => $this->str('direction'), 'qty' => $this->str('qty'), 'reason' => $this->str('reason'), 'notes' => $this->str('notes')];
        $this->saving(function () use ($row) {
            if ($row['item_id'] === '') {
                throw new \App\Core\AppError('Choose the item.');
            }
            if (!is_numeric($row['qty']) || (float) $row['qty'] <= 0) {
                throw new \App\Core\AppError('The quantity must be above zero.');
            }
            $out = Db::callOut('sp_stock_adjust', [$this->uid(), $row['item_id'], $row['direction'], $row['qty'], $row['reason'], $row['notes'] ?: null, client_ip()]);
            \App\Core\Flash::success($row['direction'] === 'IN'
                ? 'Stock added as lot ' . $out[0] . '. It shows in the stock ledger.'
                : 'Stock removed. It shows in the stock ledger.');
            redirect('/stock');
        }, 'stock/adjust', $this->adjustData($row));
    }

    private function renderAdjust(array $row): void
    {
        $this->render('stock/adjust', $this->adjustData($row));
    }

    private function adjustData(array $row): array
    {
        $items = [];
        foreach (Db::rows('sp_inventory_status', [$this->uid()]) as $i) {
            $items[$i['item_id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ') - ' . trim_zeros($i['physical_stock']) . ' in stock';
        }
        return ['title' => 'Adjust stock', 'row' => $row, 'items' => $items, 'reasons' => self::REASONS];
    }

    public function batches(): void
    {
        $source = (string) ($_GET['source'] ?? '');
        $source = in_array($source, ['PURCHASE', 'EXTRUSION', 'ADJUSTMENT'], true) ? $source : null;
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = Db::rows('sp_batch_list', [$this->uid(), $source]);
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn (array $b): bool => stripos($b['batch_number'] . ' ' . $b['item_name'] . ' ' . $b['item_code'], $q) !== false));
        }
        $this->render('stock/batches', ['title' => 'Batches', 'rows' => $rows, 'source' => $source, 'q' => $q]);
    }
}
