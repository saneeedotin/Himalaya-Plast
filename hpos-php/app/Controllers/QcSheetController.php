<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Csv;
use App\Core\Db;
use App\Core\Flash;
use App\Core\View;

/**
 * The production QC sheet: one per batch run on a job card (so one per machine at a time), with a reading about every half
 * hour. What is measured and what things are called comes from the QC sheet setup. The person signed in signs each reading;
 * readings outside the product's limits are flagged. Readings are never edited, only voided with a reason.
 */
final class QcSheetController extends Controller
{
    /** Header items in order, and where each comes from on the sheet row */
    public const HEADER = ['DIE_NO' => 'die_code', 'MFG_DATE' => 'mfg_date', 'BATCH_NO' => 'batch_number', 'DTD' => 'dtd', 'M_NO' => 'm_no',
        'PRODUCT_CODE' => 'item_code', 'COLOUR' => 'colour', 'METRES' => 'metres', 'X1' => 'x1', 'X2' => 'x2', 'X3' => 'x3'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['OPEN', 'CLOSED'], true) ? $status : null;
        $machine = $this->idParam('machine');
        $this->render('quality/sheets/index', ['title' => 'QC sheets', 'sheets' => Db::rows('sp_qc_sheet_list', [$this->uid(), $status, $machine ?: null]), 'status' => $status, 'machine' => $machine]);
    }

    public function create(): void
    {
        $this->render('quality/sheets/form', $this->formData(['job_card_id' => $this->idParam('job_card'), 'mfg_date' => date('Y-m-d')]));
    }

    public function store(): void
    {
        $row = ['job_card_id' => $this->str('job_card_id'), 'mfg_date' => $this->str('mfg_date'), 'dtd' => $this->str('dtd'), 'm_no' => $this->str('m_no'),
            'metres' => $this->str('metres'), 'colour' => $this->str('colour'), 'x1' => $this->str('x1'), 'x2' => $this->str('x2'), 'x3' => $this->str('x3')];
        $this->saving(function () use ($row) {
            if ($row['job_card_id'] === '') {
                throw new AppError('Choose the job card this sheet is for.');
            }
            if ($row['metres'] !== '' && (!is_numeric($row['metres']) || (float) $row['metres'] < 0)) {
                throw new AppError('Metres must be a number, zero or more.');
            }
            $id = (string) Db::callOut('sp_qc_sheet_open', [$this->uid(), $row['job_card_id'], $this->dateOrNull($row['dtd']), $row['m_no'] ?: null,
                $this->dateOrNull($row['mfg_date']), $row['metres'] === '' ? null : $row['metres'], $row['colour'] ?: null,
                $row['x1'] ?: null, $row['x2'] ?: null, $row['x3'] ?: null])[0];
            Flash::success('QC sheet started. Add a reading about every half hour.');
            redirect('/quality/sheets/' . $id);
        }, 'quality/sheets/form', $this->formData($row));
    }

    public function show(array $p): void
    {
        $d = $this->load($p['id']);
        $this->render('quality/sheets/show', ['title' => $d['s']['sheet_number']] + $d);
    }

    public function addRow(array $p): void
    {
        $at = $this->str('measured_at');
        $when = $at === '' ? null : $this->dt('measured_at');
        if ($at !== '' && $when === null) {
            throw new AppError('The time is not a valid date and time.');
        }
        $values = [];
        foreach ((array) ($_POST['v'] ?? []) as $code => $value) {
            $value = trim(is_array($value) ? '' : (string) $value);
            if ($value !== '') {
                $values[] = ['code' => (string) $code, 'value' => $value];
            }
        }
        Db::callOut('sp_qc_sheet_add_row', [$this->uid(), $p['id'], $when, json_encode($values)]);
        Flash::success('Reading added.');
        redirect('/quality/sheets/' . $p['id']);
    }

    public function voidRow(array $p): void
    {
        Db::call('sp_qc_sheet_void_row', [$this->uid(), $p['row'], $this->str('reason')]);
        Flash::success('Reading voided. It stays on the sheet, struck through.');
        redirect('/quality/sheets/' . $p['id']);
    }

    public function close(array $p): void
    {
        Db::call('sp_qc_sheet_close', [$this->uid(), $p['id']]);
        Flash::success('Sheet closed.');
        redirect('/quality/sheets/' . $p['id']);
    }

    public function print(array $p): void
    {
        $d = $this->load($p['id']);
        View::render('quality/sheets/print', ['title' => $d['s']['sheet_number']] + $d, 'bare');
    }

    /** The sheet in the client's own column order and words. */
    public function csv(array $p): void
    {
        $d = $this->load($p['id']);
        $s = $d['s'];
        $L = $d['labels'];
        $out = [];
        $line = [];
        foreach (self::HEADER as $key => $col) {
            if ($L[$key]['visible']) {
                $line[] = strtoupper($L[$key]['label']);
                $line[] = $this->headerValue($key, $s);
            }
        }
        $out[] = $line;
        $out[] = [];
        $head = [strtoupper($L['SR_NO']['label'])];
        foreach ($d['fields'] as $f) {
            $head[] = strtoupper($f['label'] . ($f['unit'] ? ' (' . $f['unit'] . ')' : ''));
        }
        array_push($head, strtoupper($L['DATE_TIME']['label']), strtoupper($L['AUTHORISED']['label']), 'OUT OF RANGE', 'VOID');
        $out[] = $head;
        foreach ($d['rows'] as $r) {
            $row = ['L-' . str_pad((string) $r['sr_no'], 2, '0', STR_PAD_LEFT)];
            foreach ($d['fields'] as $f) {
                $row[] = self::cell($f, $d['values'][$r['id']][$f['code']] ?? null);
            }
            $row[] = substr((string) $r['measured_at'], 0, 16);
            $row[] = $r['authorised_name'];
            $row[] = $r['out_params'] ? str_replace(',', ' ', (string) $r['out_params']) : '';
            $row[] = (int) $r['is_void'] === 1 ? 'VOID: ' . $r['void_reason'] : '';
            $out[] = $row;
        }
        Csv::send('qc_sheet_' . $s['sheet_number'] . '.csv', ['QC FORMAT', $s['sheet_number']], $out);
    }

    /** A reading as text: a number without trailing zeros, a choice by its name, or the text. */
    public static function cell(array $field, ?array $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($field['kind'] === 'NUMBER') {
            return $v['num_value'] === null ? '' : trim_zeros($v['num_value']);
        }
        $text = (string) ($v['text_value'] ?? '');
        if ($field['kind'] === 'CHOICE') {
            foreach (explode(';', (string) $field['choices']) as $pair) {
                [$val, $name] = array_pad(explode('=', $pair, 2), 2, '');
                if ($val === $text) {
                    return $name;
                }
            }
        }
        return $text;
    }

    /** The value shown next to a header label. */
    public function headerValue(string $key, array $s): string
    {
        $v = $s[self::HEADER[$key]] ?? null;
        if ($v === null || $v === '') {
            return '';
        }
        return match ($key) {
            'MFG_DATE', 'DTD' => substr((string) $v, 0, 10),
            'METRES' => fmt_num($v, 0),
            default => (string) $v,
        };
    }

    /** @return array{s:array,rows:list<array>,specs:array<string,array>,values:array<string,array<string,array>>,fields:list<array>,labels:array<string,array>,ctl:self} */
    private function load(string $id): array
    {
        $sets = Db::call('sp_qc_sheet_get', [$this->uid(), $id]);
        $specs = [];
        foreach ($sets[2] ?? [] as $sp) {
            $specs[$sp['parameter']] = $sp;
        }
        $values = [];
        foreach ($sets[3] ?? [] as $v) {
            $values[$v['row_id']][$v['field_code']] = $v;
        }
        return ['s' => $this->must($sets[0][0] ?? null), 'rows' => $sets[1] ?? [], 'specs' => $specs, 'values' => $values, 'fields' => $sets[4] ?? [], 'labels' => $this->labels(), 'ctl' => $this];
    }

    /** @return array<string,array{label:string,visible:bool}> */
    private function labels(): array
    {
        $out = [];
        foreach (Db::rows('sp_qc_labels_list', [$this->uid()]) as $l) {
            $out[$l['label_key']] = ['label' => $l['label'], 'visible' => (int) $l['is_visible'] === 1];
        }
        return $out;
    }

    private function dateOrNull(string $d): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : null;
    }

    private function formData(array $row): array
    {
        return ['title' => 'Start a QC sheet', 'row' => $row, 'jobCards' => Db::rows('sp_qc_job_card_pick', [$this->uid()]), 'labels' => $this->labels()];
    }
}
