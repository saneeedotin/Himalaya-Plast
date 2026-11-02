<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class WorkstationController extends Controller
{
    public const STATUSES = ['IDLE' => 'Idle', 'RUNNING' => 'Running', 'MAINTENANCE' => 'Maintenance'];

    public function index(): void
    {
        $this->render('workstations/index', ['title' => 'Workstations', 'workstations' => Db::rows('sp_workstation_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('workstations/form', ['title' => 'New workstation', 'ws' => null, 'row' => ['status' => 'IDLE']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $id = $this->save(null, $row);
            Flash::success('Workstation created.');
            redirect('/workstations/' . $id);
        }, 'workstations/form', ['title' => 'New workstation', 'ws' => null, 'row' => $row]);
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_workstation_get', [$this->uid(), $p['id']]);
        $ws = $this->must($sets[0][0] ?? null);
        $this->render('workstations/show', ['title' => $ws['name'], 'ws' => $ws, 'jobs' => $sets[1] ?? [], 'downtime' => $sets[2] ?? []]);
    }

    public function edit(array $p): void
    {
        $ws = $this->load($p['id']);
        $this->render('workstations/form', ['title' => 'Edit workstation', 'ws' => $ws, 'row' => $ws]);
    }

    public function update(array $p): void
    {
        $ws = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($ws, $row) {
            $this->save($ws['id'], $row);
            Flash::success('Workstation saved.');
            redirect('/workstations/' . $ws['id']);
        }, 'workstations/form', ['title' => 'Edit workstation', 'ws' => $ws, 'row' => $row]);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_workstation_delete', [$this->uid(), $p['id']]);
        Flash::success('Workstation deleted.');
        redirect('/workstations');
    }

    private function load(string $id): array
    {
        return $this->must(Db::row('sp_workstation_get', [$this->uid(), $id]));
    }

    private function formRow(): array
    {
        return [
            'code'        => strtoupper($this->str('code')),
            'name'        => $this->str('name'),
            'hourly_rate' => $this->str('hourly_rate'),
            'status'      => $this->str('status') ?: 'IDLE',
        ];
    }

    private function save(?string $id, array $row): string
    {
        if ($row['code'] === '' || $row['name'] === '') {
            throw new AppError('Code and name are required.');
        }
        if ($row['hourly_rate'] !== '' && (!is_numeric($row['hourly_rate']) || (float) $row['hourly_rate'] < 0)) {
            throw new AppError('Hourly rate must be a number, zero or more.');
        }
        if (!isset(self::STATUSES[$row['status']])) {
            throw new AppError('Choose a status.');
        }
        return (string) Db::callOut('sp_workstation_save', [
            $this->uid(), $id, $row['code'], $row['name'], $row['hourly_rate'] === '' ? 0 : $row['hourly_rate'], $row['status'],
        ])[0];
    }
}
