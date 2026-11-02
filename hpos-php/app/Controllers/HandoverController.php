<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Shift handover notes between operators. */
final class HandoverController extends Controller
{
    public const SHIFTS = ['MORNING' => 'Morning', 'AFTERNOON' => 'Afternoon', 'NIGHT' => 'Night'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['PENDING', 'ACCEPTED'], true) ? $status : null;
        $this->render('handovers/index', ['title' => 'Shift handovers', 'rows' => Db::rows('sp_shift_handover_list', [$this->uid(), $status]), 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('handovers/form', $this->formData(['shift_type' => 'MORNING']));
    }

    public function store(): void
    {
        $row = ['shift_type' => $this->str('shift_type'), 'workstation_id' => $this->str('workstation_id'), 'scrap_qty' => $this->str('scrap_qty'), 'notes' => $this->str('notes')];
        $this->saving(function () use ($row) {
            if (!isset(self::SHIFTS[$row['shift_type']])) {
                throw new AppError('Choose the shift.');
            }
            if ($row['workstation_id'] === '') {
                throw new AppError('Choose the workstation.');
            }
            if ($row['scrap_qty'] !== '' && (!is_numeric($row['scrap_qty']) || (float) $row['scrap_qty'] < 0)) {
                throw new AppError('Scrap must be a number, zero or more.');
            }
            Db::callOut('sp_shift_handover_create', [$this->uid(), $row['shift_type'], $row['workstation_id'], $row['scrap_qty'] === '' ? 0 : $row['scrap_qty'], $row['notes'] ?: null]);
            Flash::success('Handover recorded. The next shift can accept it.');
            redirect('/handovers');
        }, 'handovers/form', $this->formData($row));
    }

    public function accept(array $p): void
    {
        Db::call('sp_shift_handover_accept', [$this->uid(), $p['id']]);
        Flash::success('Handover accepted.');
        redirect('/handovers');
    }

    private function formData(array $row): array
    {
        $ws = [];
        foreach (Db::rows('sp_workstation_list', [$this->uid()]) as $w) {
            $ws[$w['id']] = $w['code'] . ' ' . $w['name'];
        }
        return ['title' => 'New handover', 'row' => $row, 'workstations' => $ws];
    }
}
