<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Quality inspections against a batch. */
final class QualityController extends Controller
{
    public const STATUSES = ['PASS' => 'Pass', 'REWORK' => 'Rework', 'REJECT' => 'Reject', 'SCRAP' => 'Scrap', 'PENDING' => 'Pending', 'DRAFT' => 'Draft'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = isset(self::STATUSES[$status]) ? $status : null;
        $this->render('quality/index', ['title' => 'Quality', 'reports' => Db::rows('sp_qc_list', [$this->uid(), $status]), 'status' => $status]);
    }

    public function show(array $p): void
    {
        $report = null;
        foreach (Db::rows('sp_qc_list', [$this->uid(), null]) as $r) {
            if ($r['id'] === $p['id']) {
                $report = $r;
                break;
            }
        }
        $report = $this->must($report);
        $data = json_decode((string) $report['qc_data'], true);
        $this->render('quality/show', ['title' => $report['report_number'], 'report' => $report, 'data' => is_array($data) ? $data : []]);
    }

    public function create(): void
    {
        $batch = (string) ($_GET['batch'] ?? '');
        $this->render('quality/form', $this->formData(['batch_number' => $batch, 'status' => 'PASS', 'sample_size' => '5'], $this->templateRows($batch)));
    }

    public function store(): void
    {
        $row = [
            'batch_number' => $this->str('batch_number'), 'status' => $this->str('status'), 'sample_size' => $this->str('sample_size'),
            'rework_notes' => $this->str('rework_notes'),
        ];
        $names = (array) ($_POST['param'] ?? []);
        $values = (array) ($_POST['value'] ?? []);
        $rows = [];
        foreach ($names as $i => $n) {
            $rows[] = ['name' => trim((string) $n), 'value' => trim((string) ($values[$i] ?? ''))];
        }
        $this->saving(function () use ($row, $rows) {
            if ($row['batch_number'] === '') {
                throw new AppError('Choose the batch.');
            }
            if (!isset(self::STATUSES[$row['status']])) {
                throw new AppError('Choose the result.');
            }
            if (!ctype_digit($row['sample_size']) || (int) $row['sample_size'] < 1) {
                throw new AppError('The sample size must be a whole number, 1 or more.');
            }
            $data = [];
            foreach ($rows as $r) {
                if ($r['name'] === '' && $r['value'] === '') {
                    continue;
                }
                if ($r['name'] === '') {
                    throw new AppError('Every measurement needs a name.');
                }
                $data[$r['name']] = is_numeric($r['value']) ? $r['value'] + 0 : $r['value'];
            }
            if (in_array($row['status'], ['REWORK', 'REJECT'], true) && $row['rework_notes'] === '') {
                throw new AppError('Say what is wrong in the notes when the result is rework or reject.');
            }
            Db::callOut('sp_qc_submit', [$this->uid(), $row['batch_number'], $row['status'], (int) $row['sample_size'], json_encode((object) $data), $row['rework_notes'] ?: null, null]);
            Flash::success($row['status'] === 'SCRAP' ? 'Inspection saved. The whole batch was written off.' : 'Inspection saved.');
            redirect('/quality');
        }, 'quality/form', $this->formData($row, $rows ?: [['name' => '', 'value' => '']]));
    }

    /**
     * Measurement names from the item's QC template. The template is a JSON list whose entries are
     * names, or objects with a name / label / key / parameter field.
     *
     * @return list<array{name:string,value:string}>
     */
    private function templateRows(string $batch): array
    {
        if ($batch === '') {
            return [['name' => '', 'value' => '']];
        }
        $tpl = Db::row('sp_qc_get_template', [$this->uid(), $batch]);
        $list = json_decode((string) ($tpl['template'] ?? ''), true);
        $rows = [];
        foreach (is_array($list) ? $list : [] as $k => $entry) {
            $name = is_string($entry) ? $entry : (is_array($entry) ? (string) ($entry['name'] ?? $entry['label'] ?? $entry['key'] ?? $entry['parameter'] ?? '') : '');
            if ($name === '' && is_string($k)) {
                $name = $k;
            }
            if ($name !== '') {
                $rows[] = ['name' => $name, 'value' => ''];
            }
        }
        return $rows ?: [['name' => '', 'value' => '']];
    }

    private function formData(array $row, array $rows): array
    {
        $batches = [];
        foreach (Db::rows('sp_batch_list', [$this->uid(), null]) as $b) {
            $batches[$b['batch_number']] = $b['batch_number'] . ' - ' . $b['item_name'] . ' (' . trim_zeros($b['quantity']) . ' ' . $b['uom'] . ')';
        }
        return ['title' => 'New inspection', 'row' => $row, 'rows' => $rows, 'batches' => $batches];
    }
}
