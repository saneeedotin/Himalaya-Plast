<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class ItemController extends Controller
{
    public const CATEGORIES = [
        'RAW_MATERIAL'   => 'Raw material',
        'FINISHED_GOODS' => 'Finished goods',
        'PACKAGING'      => 'Packaging',
        'SCRAP'          => 'Scrap',
    ];

    public function index(): void
    {
        $category = (string) ($_GET['category'] ?? '');
        $category = isset(self::CATEGORIES[$category]) ? $category : null;
        $this->render('items/index', [
            'title'    => 'Items',
            'items'    => Db::rows('sp_item_list', [$this->uid(), $category]),
            'category' => $category,
        ]);
    }

    public function create(): void
    {
        $this->render('items/form', ['title' => 'New item', 'item' => null, 'row' => ['category' => 'RAW_MATERIAL']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $id = $this->save(null, $row);
            Flash::success('Item created.');
            redirect('/items/' . $id);
        }, 'items/form', ['title' => 'New item', 'item' => null, 'row' => $row]);
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_item_get', [$this->uid(), $p['id']]);
        $item = $this->must($sets[0][0] ?? null);
        $specs = [];
        foreach (Db::rows('sp_qc_spec_list', [$this->uid(), $p['id']]) as $s) {
            $specs[$s['parameter']] = $s;
        }
        $suppliers = [];
        $specFields = $item['category'] === 'FINISHED_GOODS' ? $this->specFields() : [];
        if (in_array($item['category'], ['RAW_MATERIAL', 'PACKAGING'], true) && can('Suppliers', 'read')) {
            foreach (Db::rows('sp_supplier_list', [$this->uid()]) as $s) {
                if ($s['status'] === 'ACTIVE' || $s['id'] === $item['preferred_supplier_id']) {
                    $suppliers[$s['id']] = $s['name'];
                }
            }
        }
        $this->render('items/show', ['title' => $item['name'], 'item' => $item, 'boms' => $sets[1] ?? [], 'ledger' => $sets[2] ?? [], 'specs' => $specs, 'specFields' => $specFields, 'suppliers' => $suppliers]);
    }

    /** The measured fields that can have limits (code => name), from the QC sheet setup. @return array<string,string> */
    private function specFields(): array
    {
        $out = [];
        foreach (Db::rows('sp_qc_field_list', [$this->uid()]) as $f) {
            if ($f['kind'] === 'NUMBER' && (int) $f['has_limits'] === 1 && (int) $f['is_active'] === 1) {
                $out[$f['code']] = $f['label'] . ($f['unit'] ? ' (' . $f['unit'] . ')' : '');
            }
        }
        return $out;
    }

    /** Lead time, smallest order and preferred supplier, which the buying advice works from. */
    public function saveBuying(array $p): void
    {
        $item = $this->load($p['id']);
        $lead = $this->str('lead_time_days');
        $lot = $this->str('reorder_qty');
        if ($lead !== '' && !ctype_digit($lead)) {
            throw new AppError('The lead time is a whole number of days.');
        }
        if ($lot !== '' && (!is_numeric($lot) || (float) $lot < 0)) {
            throw new AppError('The smallest order must be a number, zero or more.');
        }
        Db::call('sp_item_buying_save', [$this->uid(), $item['id'], $lead === '' ? null : (int) $lead, $lot === '' ? null : $lot, $this->nul('preferred_supplier_id')]);
        Flash::success('Buying details saved.');
        redirect('/items/' . $item['id']);
    }

    /** Colour and the pin, width, leg and weight limits that the QC sheet checks readings against. */
    public function saveSpecs(array $p): void
    {
        $item = $this->load($p['id']);
        $specs = [];
        $names = $this->specFields();
        foreach (array_keys($names) as $k) {
            $vals = [];
            foreach (['target', 'min', 'max'] as $f) {
                $v = $this->str("{$k}_{$f}");
                if ($v !== '' && !is_numeric($v)) {
                    Flash::error($names[$k] . ' ' . $f . ' must be a number.');
                    redirect('/items/' . $item['id']);
                }
                $vals[$f] = $v === '' ? null : (float) $v;
            }
            if ($vals['target'] !== null || $vals['min'] !== null || $vals['max'] !== null) {
                $specs[] = ['parameter' => $k] + $vals;
            }
        }
        Db::call('sp_item_spec_save', [$this->uid(), $item['id'], $this->str('colour') ?: null, json_encode($specs)]);
        Flash::success('Colour and QC limits saved.');
        redirect('/items/' . $item['id']);
    }

    public function edit(array $p): void
    {
        $item = $this->load($p['id']);
        $this->render('items/form', ['title' => 'Edit item', 'item' => $item, 'row' => $item]);
    }

    public function update(array $p): void
    {
        $item = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($item, $row) {
            $this->save($item['id'], $row);
            Flash::success('Item saved.');
            redirect('/items/' . $item['id']);
        }, 'items/form', ['title' => 'Edit item', 'item' => $item, 'row' => $row]);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_item_delete', [$this->uid(), $p['id']]);
        Flash::success('Item deleted.');
        redirect('/items');
    }

    private function load(string $id): array
    {
        return $this->must(Db::row('sp_item_get', [$this->uid(), $id]));
    }

    private function formRow(): array
    {
        return [
            'code'            => strtoupper($this->str('code')),
            'name'            => $this->str('name'),
            'category'        => $this->str('category'),
            'uom'             => $this->str('uom'),
            'hsn_code'        => $this->str('hsn_code'),
            'standard_cost'   => $this->str('standard_cost'),
            'min_stock_level' => $this->str('min_stock_level'),
            'qc_template'     => $this->str('qc_template'),
        ];
    }

    private function save(?string $id, array $row): string
    {
        if ($row['code'] === '' || $row['name'] === '' || $row['uom'] === '') {
            throw new AppError('Code, name and unit are required.');
        }
        if (!isset(self::CATEGORIES[$row['category']])) {
            throw new AppError('Choose a category.');
        }
        foreach (['standard_cost' => 'Standard cost', 'min_stock_level' => 'Minimum stock'] as $k => $label) {
            if ($row[$k] !== '' && (!is_numeric($row[$k]) || (float) $row[$k] < 0)) {
                throw new AppError("$label must be a number, zero or more.");
            }
        }
        if ($row['qc_template'] !== '') {
            json_decode($row['qc_template']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AppError('The QC template is not valid JSON.');
            }
        }
        return (string) Db::callOut('sp_item_save', [
            $this->uid(), $id, $row['code'], $row['name'], $row['category'], $row['uom'], $row['hsn_code'] ?: null,
            $row['standard_cost'] === '' ? 0 : $row['standard_cost'],
            $row['min_stock_level'] === '' ? 0 : $row['min_stock_level'],
            $row['qc_template'] ?: null,
        ])[0];
    }
}
