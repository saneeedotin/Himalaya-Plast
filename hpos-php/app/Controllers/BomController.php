<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class BomController extends Controller
{
    public function index(): void
    {
        $this->render('boms/index', ['title' => 'Bills of materials', 'boms' => Db::rows('sp_bom_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('boms/form', $this->formData(null, ['output_qty' => '100', 'scrap_percent' => '2.5'], [], ['title' => 'New BOM']));
    }

    public function store(): void
    {
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($row, $lines) {
            $id = $this->save(null, $row, $lines);
            Flash::success('BOM created.');
            redirect('/boms/' . $id);
        }, 'boms/form', $this->formData(null, $row, $lines, ['title' => 'New BOM']));
    }

    public function show(array $p): void
    {
        [$bom, $lines] = $this->load($p['id']);
        $this->render('boms/show', ['title' => $bom['name'], 'bom' => $bom, 'lines' => $lines]);
    }

    public function edit(array $p): void
    {
        [$bom, $lines] = $this->load($p['id']);
        $row = $bom;
        $row['output_qty'] = trim_zeros($bom['output_qty']);
        $row['scrap_percent'] = trim_zeros((string) round((float) $bom['scrap_factor'] * 100, 4));
        foreach ($lines as &$l) {
            $l['qty_per_unit'] = trim_zeros($l['qty_per_unit']);
        }
        unset($l);
        $this->render('boms/form', $this->formData($bom, $row, $lines, ['title' => 'Edit BOM']));
    }

    public function update(array $p): void
    {
        [$bom] = $this->load($p['id']);
        [$row, $lines] = $this->readForm();
        $this->saving(function () use ($bom, $row, $lines) {
            $this->save($bom['id'], $row, $lines);
            Flash::success('BOM saved.');
            redirect('/boms/' . $bom['id']);
        }, 'boms/form', $this->formData($bom, $row, $lines, ['title' => 'Edit BOM']));
    }

    public function destroy(array $p): void
    {
        Db::call('sp_bom_delete', [$this->uid(), $p['id']]);
        Flash::success('BOM deleted.');
        redirect('/boms');
    }

    /** @return array{0:array,1:list<array>} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_bom_get', [$this->uid(), $id]);
        return [$this->must($sets[0][0] ?? null), $sets[1] ?? []];
    }

    /** @return array{0:array,1:list<array{rm_item_id:string,qty_per_unit:string}>} */
    private function readForm(): array
    {
        // people type scrap as a percentage (2.5); the database keeps it as a fraction (0.025)
        $percent = $this->str('scrap_percent');
        $row = [
            'name'          => $this->str('name'),
            'fg_item_id'    => $this->str('fg_item_id'),
            'output_qty'    => $this->str('output_qty'),
            'scrap_percent' => $percent,
            'scrap_factor'  => is_numeric($percent) ? (string) round((float) $percent / 100, 4) : $percent,
        ];
        $ids = (array) ($_POST['rm_item_id'] ?? []);
        $qtys = (array) ($_POST['qty_per_unit'] ?? []);
        $lines = [];
        foreach ($ids as $i => $id) {
            $id = trim((string) $id);
            $qty = trim((string) ($qtys[$i] ?? ''));
            if ($id !== '' || $qty !== '') {
                $lines[] = ['rm_item_id' => $id, 'qty_per_unit' => $qty];
            }
        }
        return [$row, $lines];
    }

    private function save(?string $id, array $row, array $lines): string
    {
        if ($row['name'] === '' || $row['fg_item_id'] === '') {
            throw new AppError('Name and finished good are required.');
        }
        if (!is_numeric($row['output_qty']) || (float) $row['output_qty'] <= 0) {
            throw new AppError('Output quantity must be more than zero.');
        }
        if (!is_numeric($row['scrap_factor']) || (float) $row['scrap_factor'] < 0 || (float) $row['scrap_factor'] >= 1) {
            throw new AppError('Scrap must be from 0% up to (not including) 100%. For 2.5% scrap enter 2.5.');
        }
        $materials = [];
        $seen = [];
        foreach ($lines as $l) {
            if ($l['rm_item_id'] === '' || !is_numeric($l['qty_per_unit']) || (float) $l['qty_per_unit'] <= 0) {
                throw new AppError('Every material line needs a raw material and a quantity above zero.');
            }
            if (isset($seen[$l['rm_item_id']])) {
                throw new AppError('A raw material can appear only once in a BOM.');
            }
            $seen[$l['rm_item_id']] = true;
            $materials[] = ['rmItemId' => $l['rm_item_id'], 'qtyPerUnit' => (float) $l['qty_per_unit']];
        }
        if (!$materials) {
            throw new AppError('Add at least one raw material.');
        }
        return (string) Db::callOut('sp_bom_save', [
            $this->uid(), $id, $row['name'], $row['fg_item_id'], $row['output_qty'], $row['scrap_factor'], json_encode($materials),
        ])[0];
    }

    private function formData(?array $bom, array $row, array $lines, array $extra): array
    {
        $options = static fn (array $items): array => array_column($items, null, 'id');
        $fg = $options(Db::rows('sp_item_list', [$this->uid(), 'FINISHED_GOODS']));
        $rm = $options(Db::rows('sp_item_list', [$this->uid(), 'RAW_MATERIAL']));
        return $extra + ['bom' => $bom, 'row' => $row, 'lines' => $lines ?: [['rm_item_id' => '', 'qty_per_unit' => '']], 'fgItems' => $fg, 'rmItems' => $rm];
    }
}
