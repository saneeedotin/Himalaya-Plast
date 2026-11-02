<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

final class LeadController extends Controller
{
    public const STATUSES = ['NEW' => 'New', 'CONTACTED' => 'Contacted', 'QUALIFIED' => 'Qualified', 'REJECTED' => 'Rejected'];

    public function index(): void
    {
        $this->render('leads/index', ['title' => 'Supplier leads', 'leads' => Db::rows('sp_supplier_lead_list', [$this->uid()])]);
    }

    public function create(): void
    {
        $this->render('leads/form', ['title' => 'New lead', 'lead' => null, 'row' => ['status' => 'NEW']]);
    }

    public function store(): void
    {
        $row = $this->formRow();
        $this->saving(function () use ($row) {
            $this->save(null, $row);
            Flash::success('Lead added.');
            redirect('/leads');
        }, 'leads/form', ['title' => 'New lead', 'lead' => null, 'row' => $row]);
    }

    public function edit(array $p): void
    {
        $lead = $this->load($p['id']);
        $this->render('leads/form', ['title' => 'Edit lead', 'lead' => $lead, 'row' => $lead]);
    }

    public function update(array $p): void
    {
        $lead = $this->load($p['id']);
        $row = $this->formRow();
        $this->saving(function () use ($lead, $row) {
            $this->save($lead['id'], $row);
            Flash::success('Lead saved.');
            redirect('/leads');
        }, 'leads/form', ['title' => 'Edit lead', 'lead' => $lead, 'row' => $row]);
    }

    /** There is no get procedure for one lead, so find it in the list. */
    private function load(string $id): array
    {
        foreach (Db::rows('sp_supplier_lead_list', [$this->uid()]) as $lead) {
            if ($lead['id'] === $id) {
                return $lead;
            }
        }
        $this->notFound();
    }

    private function formRow(): array
    {
        return [
            'company_name'   => $this->str('company_name'),
            'contact_name'   => $this->str('contact_name'),
            'material'       => $this->str('material'),
            'source'         => $this->str('source'),
            'status'         => $this->str('status') ?: 'NEW',
            'next_follow_up' => $this->dt('next_follow_up'),
            'notes'          => $this->str('notes'),
        ];
    }

    private function save(?string $id, array $row): void
    {
        if ($row['company_name'] === '') {
            throw new AppError('The company name is required.');
        }
        if (!isset(self::STATUSES[$row['status']])) {
            throw new AppError('Choose a status.');
        }
        Db::callOut('sp_supplier_lead_save', [
            $this->uid(), $id, $row['company_name'], $row['contact_name'] ?: null, $row['material'] ?: null,
            $row['source'] ?: null, $row['status'], $row['next_follow_up'], $row['notes'] ?: null,
        ]);
    }
}
