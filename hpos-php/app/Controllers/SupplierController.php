<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class SupplierController extends Controller
{
    public function index(): void
    {
        $this->render('suppliers/index', ['title' => 'Suppliers', 'suppliers' => Db::rows('sp_supplier_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('suppliers/form', ['title' => 'New supplier', 'supplier' => null, 'row' => ['status' => 'ACTIVE']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $id = $this->save(null, $row);
            Flash::success('Supplier created.');
            redirect('/suppliers/' . $id);
        }, 'suppliers/form', ['title' => 'New supplier', 'supplier' => null, 'row' => $row]);
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_supplier_get', [$this->uid(), $p['id']]);
        $supplier = $this->must($sets[0][0] ?? null);
        $this->render('suppliers/show', ['title' => $supplier['name'], 'supplier' => $supplier, 'orders' => $sets[1] ?? []]);
    }

    public function edit(array $p): void
    {
        $supplier = $this->load($p['id']);
        $this->render('suppliers/form', ['title' => 'Edit supplier', 'supplier' => $supplier, 'row' => $supplier]);
    }

    public function update(array $p): void
    {
        $supplier = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($supplier, $row) {
            $this->save($supplier['id'], $row);
            Flash::success('Supplier saved.');
            redirect('/suppliers/' . $supplier['id']);
        }, 'suppliers/form', ['title' => 'Edit supplier', 'supplier' => $supplier, 'row' => $row]);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_supplier_delete', [$this->uid(), $p['id']]);
        Flash::success('Supplier deleted.');
        redirect('/suppliers');
    }

    private function load(string $id): array
    {
        return $this->must(Db::row('sp_supplier_get', [$this->uid(), $id]));
    }

    private function formRow(): array
    {
        return [
            'name'   => $this->str('name'),
            'gstin'  => strtoupper($this->str('gstin')),
            'email'  => $this->str('email'),
            'phone'  => $this->str('phone'),
            'status' => $this->str('status') ?: 'ACTIVE',
        ];
    }

    private function save(?string $id, array $row): string
    {
        if ($row['name'] === '') {
            throw new AppError('The supplier name is required.');
        }
        if ($row['gstin'] !== '' && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $row['gstin'])) {
            throw new AppError('That GSTIN is not in the 15-character GST format.');
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new AppError('That email address does not look right.');
        }
        if (!in_array($row['status'], ['ACTIVE', 'INACTIVE'], true)) {
            throw new AppError('Choose a status.');
        }
        return (string) Db::callOut('sp_supplier_save', [
            $this->uid(), $id, $row['name'], $row['gstin'] ?: null, $row['email'] ?: null, $row['phone'] ?: null, $row['status'],
        ])[0];
    }
}
