<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class CustomerController extends Controller
{
    public function index(): void
    {
        $this->render('customers/index', ['title' => 'Customers', 'customers' => Db::rows('sp_customer_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('customers/form', ['title' => 'New customer', 'customer' => null, 'row' => []]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $id = $this->save(null, $row);
            Flash::success('Customer created.');
            redirect('/customers/' . $id);
        }, 'customers/form', ['title' => 'New customer', 'customer' => null, 'row' => $row]);
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_customer_get', [$this->uid(), $p['id']]);
        $customer = $this->must($sets[0][0] ?? null);
        $this->render('customers/show', [
            'title'     => $customer['name'],
            'customer'  => $customer,
            'followUps' => $sets[1] ?? [],
            'orders'    => $sets[2] ?? [],
            'ledger'    => $sets[3][0] ?? null,
            'insight'   => $sets[4][0] ?? null,
        ]);
    }

    public function edit(array $p): void
    {
        $customer = $this->load($p['id']);
        $this->render('customers/form', ['title' => 'Edit customer', 'customer' => $customer, 'row' => $customer]);
    }

    public function update(array $p): void
    {
        $customer = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($customer, $row) {
            $this->save($customer['id'], $row);
            Flash::success('Customer saved.');
            redirect('/customers/' . $customer['id']);
        }, 'customers/form', ['title' => 'Edit customer', 'customer' => $customer, 'row' => $row]);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_customer_delete', [$this->uid(), $p['id']]);
        Flash::success('Customer deleted.');
        redirect('/customers');
    }

    public function addFollowUp(array $p): void
    {
        $notes = $this->str('notes');
        if ($notes === '') {
            throw new AppError('Write a note for the follow-up.');
        }
        Db::callOut('sp_customer_follow_up_log', [$this->uid(), $p['id'], $notes, $this->dt('expected_next'), $this->dt('reminder')]);
        Flash::success('Follow-up logged.');
        redirect('/customers/' . $p['id']);
    }

    public function followUpStatus(array $p): void
    {
        Db::call('sp_customer_follow_up_set_status', [$this->uid(), $p['id'], $this->str('status')]);
        redirect('/customers/' . $this->backId());
    }

    public function followUpDelete(array $p): void
    {
        Db::call('sp_customer_follow_up_delete', [$this->uid(), $p['id']]);
        redirect('/customers/' . $this->backId());
    }

    private function load(string $id): array
    {
        return $this->must(Db::row('sp_customer_get', [$this->uid(), $id]));
    }

    /** @return array{name:string,gstin:string,email:string,phone:string} */
    private function formRow(): array
    {
        return [
            'name'  => $this->str('name'),
            'gstin' => strtoupper($this->str('gstin')),
            'email' => $this->str('email'),
            'phone' => $this->str('phone'),
        ];
    }

    private function save(?string $id, array $row): string
    {
        if ($row['name'] === '') {
            throw new AppError('The customer name is required.');
        }
        if ($row['gstin'] !== '' && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $row['gstin'])) {
            throw new AppError('That GSTIN is not in the 15-character GST format.');
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new AppError('That email address does not look right.');
        }
        return (string) Db::callOut('sp_customer_save', [
            $this->uid(), $id, $row['name'], $row['gstin'] ?: null, $row['email'] ?: null, $row['phone'] ?: null,
        ])[0];
    }

    /** Customer id the follow-up form came from; only a UUID is accepted. */
    private function backId(): string
    {
        $id = $this->str('customer_id');
        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : '';
    }
}
