<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/**
 * Raw material kept next to the machines: issue sacks from the store to a machine, return what is not used, and see how
 * many hours each machine's material will last. Total stock does not change; this tracks where it is.
 */
final class MachineStockController extends Controller
{
    public function index(): void
    {
        $uid = $this->uid();
        $pick = Db::call('sp_machine_stock_pick', [$uid]);
        $machines = [];
        foreach ($pick[0] ?? [] as $m) {
            $machines[$m['id']] = $m['code'] . ' ' . $m['name'];
        }
        $materials = [];
        $store = [];
        foreach ($pick[1] ?? [] as $i) {
            $materials[$i['item_id']] = $i['code'] . ' ' . $i['name'] . ' (' . $i['uom'] . ')';
            $store[$i['item_id']] = $i;
        }
        $this->render('machine_stock/index', [
            'title' => 'Machine stock', 'machines' => $machines, 'materials' => $materials, 'store' => $store,
            'levels' => Db::rows('sp_machine_stock_levels', [$uid]), 'recent' => Db::rows('sp_machine_stock_recent', [$uid, 30]),
            'lotSacks' => (int) $this->setting('issue_lot_sacks', 50),
        ]);
    }

    /** bags x weight of one bag = the quantity, like Receive material */
    public function issue(): void
    {
        [$ws, $item, $qty, $bags] = $this->fields();
        Db::callOut('sp_machine_stock_issue', [$this->uid(), $ws, $item, $qty, $bags, $this->nul('note')]);
        Flash::success('Issued ' . trim_zeros($qty) . ' to the machine.');
        redirect('/machine-stock');
    }

    public function giveBack(): void
    {
        [$ws, $item, $qty] = $this->fields();
        Db::callOut('sp_machine_stock_return', [$this->uid(), $ws, $item, $qty, $this->nul('note')]);
        Flash::success('Returned ' . trim_zeros($qty) . ' to the store.');
        redirect('/machine-stock');
    }

    /** @return array{0:string,1:string,2:string,3:?int} */
    private function fields(): array
    {
        $ws = $this->str('workstation_id');
        $item = $this->str('item_id');
        $weight = $this->str('weight');
        $bags = $this->str('bags') === '' ? '1' : $this->str('bags');
        if ($ws === '' || $item === '') {
            throw new AppError('Choose the machine and the material.');
        }
        if (!ctype_digit($bags) || (int) $bags < 1 || (int) $bags > 5000) {
            throw new AppError('The number of sacks is a whole number from 1 to 5,000.');
        }
        if (!is_numeric($weight) || (float) $weight <= 0) {
            throw new AppError('Enter the weight of one sack above zero.');
        }
        return [$ws, $item, (string) round((int) $bags * (float) $weight, 3), (int) $bags];
    }

    /** A whole-number setting, or the default when the person may not read settings (the screen still works). */
    private function setting(string $key, int $default): int
    {
        try {
            foreach (Db::rows('sp_settings_list', [$this->uid()]) as $s) {
                if ($s['setting_key'] === $key && ctype_digit((string) $s['setting_value'])) {
                    return (int) $s['setting_value'];
                }
            }
        } catch (AppError) {
            // not allowed to read settings: use the default
        }
        return $default;
    }
}
