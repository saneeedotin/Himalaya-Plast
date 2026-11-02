<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** People who might become customers: new lead, contacted, quoted, then won (converted to a customer) or lost. */
final class CustomerLeadController extends Controller
{
    public const STATUSES = ['NEW' => 'New', 'CONTACTED' => 'Contacted', 'QUOTED' => 'Quoted', 'WON' => 'Won', 'LOST' => 'Lost'];
    /** What can be picked on the form; Won only happens through Convert to customer. */
    public const EDITABLE = ['NEW' => 'New', 'CONTACTED' => 'Contacted', 'QUOTED' => 'Quoted', 'LOST' => 'Lost'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $rows = Db::rows('sp_customer_lead_list', [$this->uid()]);
        if (isset(self::STATUSES[$status])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $status));
        } else {
            $status = '';
        }
        $this->render('customer_leads/index', ['title' => 'Customer leads', 'leads' => $rows, 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('customer_leads/form', ['title' => 'New lead', 'lead' => null, 'row' => ['status' => 'NEW']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $this->save(null, $row);
            Flash::success('Lead added.');
            redirect('/customer-leads');
        }, 'customer_leads/form', ['title' => 'New lead', 'lead' => null, 'row' => $row]);
    }

    public function edit(array $p): void
    {
        $lead = $this->load($p['id']);
        $this->render('customer_leads/form', ['title' => 'Edit lead', 'lead' => $lead, 'row' => $lead]);
    }

    public function update(array $p): void
    {
        $lead = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($lead, $row) {
            $this->save($lead['id'], $row);
            Flash::success('Lead saved.');
            redirect('/customer-leads');
        }, 'customer_leads/form', ['title' => 'Edit lead', 'lead' => $lead, 'row' => $row]);
    }

    public function convert(array $p): void
    {
        $id = (string) Db::callOut('sp_customer_lead_convert', [$this->uid(), $p['id']])[0];
        Flash::success('Converted. The lead is now a customer: add the GSTIN and place their first order.');
        redirect('/customers/' . $id);
    }

    private function load(string $id): array
    {
        return $this->must(Db::row('sp_customer_lead_get', [$this->uid(), $id]));
    }

    private function formRow(): array
    {
        return ['company_name' => $this->str('company_name'), 'contact_name' => $this->str('contact_name'), 'phone' => $this->str('phone'),
            'email' => $this->str('email'), 'source' => $this->str('source'), 'status' => $this->str('status') ?: 'NEW',
            'next_follow_up' => $this->dt('next_follow_up'), 'notes' => $this->str('notes')];
    }

    private function save(?string $id, array $row): void
    {
        if ($row['company_name'] === '') {
            throw new AppError('The company name is required.');
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new AppError('That email address does not look right.');
        }
        Db::callOut('sp_customer_lead_save', [$this->uid(), $id, $row['company_name'], $row['contact_name'] ?: null, $row['phone'] ?: null,
            $row['email'] ?: null, $row['source'] ?: null, $row['status'], $row['next_follow_up'], $row['notes'] ?: null]);
    }
}
