<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class DieController extends Controller
{
    public const STATUSES = ['ACTIVE' => 'Active', 'RETIRED' => 'Retired'];

    public function index(): void
    {
        $this->render('dies/index', ['title' => 'Dies', 'dies' => Db::rows('sp_die_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('dies/form', ['title' => 'New die', 'die' => null, 'row' => ['status' => 'ACTIVE', 'maintenance_threshold_hours' => '5000']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $this->save(null, $row);
            Flash::success('Die created.');
            redirect('/dies');
        }, 'dies/form', ['title' => 'New die', 'die' => null, 'row' => $row]);
    }

    public function edit(array $p): void
    {
        $die = $this->load($p['id']);
        $this->render('dies/form', ['title' => 'Edit die', 'die' => $die, 'row' => $die]);
    }

    public function update(array $p): void
    {
        $die = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($die, $row) {
            $this->save($die['id'], $row);
            Flash::success('Die saved.');
            redirect('/dies');
        }, 'dies/form', ['title' => 'Edit die', 'die' => $die, 'row' => $row]);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_die_delete', [$this->uid(), $p['id']]);
        Flash::success('Die deleted.');
        redirect('/dies');
    }

    private function load(string $id): array
    {
        foreach (Db::rows('sp_die_list', [$this->uid()]) as $die) {
            if ($die['id'] === $id) {
                return $die;
            }
        }
        $this->notFound();
    }

    private function formRow(): array
    {
        return [
            'code'                        => strtoupper($this->str('code')),
            'name'                        => $this->str('name'),
            'maintenance_threshold_hours' => $this->str('maintenance_threshold_hours'),
            'status'                      => $this->str('status') ?: 'ACTIVE',
        ];
    }

    private function save(?string $id, array $row): void
    {
        if ($row['code'] === '' || $row['name'] === '') {
            throw new AppError('Code and name are required.');
        }
        $h = $row['maintenance_threshold_hours'];
        if ($h !== '' && (!is_numeric($h) || (float) $h <= 0)) {
            throw new AppError('The maintenance threshold must be more than zero hours.');
        }
        if (!isset(self::STATUSES[$row['status']])) {
            throw new AppError('Choose a status.');
        }
        Db::callOut('sp_die_save', [$this->uid(), $id, $row['code'], $row['name'], $h === '' ? null : $h, $row['status']]);
    }
}
