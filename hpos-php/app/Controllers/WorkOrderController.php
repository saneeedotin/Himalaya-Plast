<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Flash;

/** Work orders and their job cards. */
final class WorkOrderController extends Controller
{
    public const STATUSES = ['PENDING' => 'Pending', 'IN_PROGRESS' => 'In progress', 'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled'];
    public const JOB_STATUSES = ['QUEUED', 'ACTIVE', 'COMPLETED'];

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = isset(self::STATUSES[$status]) ? $status : null;
        $rows = Db::rows('sp_work_order_list', [$this->uid()]);
        if ($status !== null) {
            $rows = array_values(array_filter($rows, static fn (array $w): bool => $w['status'] === $status));
        }
        $this->render('work_orders/index', ['title' => 'Work orders', 'workOrders' => $rows, 'status' => $status]);
    }

    public function create(): void
    {
        $this->render('work_orders/form', $this->formData(['sales_order_id' => (string) ($_GET['order'] ?? '')], ['title' => 'New work order']));
    }

    public function store(): void
    {
        $row = [
            'sales_order_id' => $this->str('sales_order_id'),
            'fg_item_id'     => $this->str('fg_item_id'),
            'planned_qty'    => $this->str('planned_qty'),
            'fg_batch_number' => $this->str('fg_batch_number'),
        ];
        $this->saving(function () use ($row) {
            if ($row['fg_item_id'] === '') {
                throw new AppError('Choose the item to make.');
            }
            if (!is_numeric($row['planned_qty']) || (float) $row['planned_qty'] <= 0) {
                throw new AppError('The planned quantity must be above zero.');
            }
            $id = (string) Db::callOut('sp_work_order_create', [
                $this->uid(), $row['sales_order_id'] ?: null, $row['fg_item_id'], $row['planned_qty'], $row['fg_batch_number'] ?: null,
            ])[0];
            Flash::success('Work order created.');
            redirect('/work-orders/' . $id);
        }, 'work_orders/form', $this->formData($row, ['title' => 'New work order']));
    }

    public function show(array $p): void
    {
        $sets = Db::call('sp_work_order_get', [$this->uid(), $p['id']]);
        $wo = $this->must($sets[0][0] ?? null);
        $workstations = $dies = [];
        if (can('Workstations', 'read')) {
            foreach (Db::rows('sp_workstation_list', [$this->uid()]) as $w) {
                $workstations[$w['id']] = $w['code'] . ' ' . $w['name'];
            }
            foreach (Db::rows('sp_die_list', [$this->uid()]) as $d) {
                if ($d['status'] === 'ACTIVE') {
                    $dies[$d['id']] = $d['code'] . ' ' . $d['name'] . ((int) $d['maintenance_due'] === 1 ? ' (service due)' : '');
                }
            }
        }
        $this->render('work_orders/show', [
            'title' => $wo['work_order_number'], 'wo' => $wo, 'jobCards' => $sets[1] ?? [], 'requirements' => $sets[2] ?? [],
            'workstations' => $workstations, 'dies' => $dies,
        ]);
    }

    public function update(array $p): void
    {
        $qty = $this->nul('planned_qty');
        if ($qty !== null && (!is_numeric($qty) || (float) $qty <= 0)) {
            throw new AppError('The planned quantity must be above zero.');
        }
        $status = $this->nul('status');
        if ($status !== null && !isset(self::STATUSES[$status])) {
            throw new AppError('Choose a valid status.');
        }
        Db::call('sp_work_order_update', [$this->uid(), $p['id'], $qty, $status, $this->nul('fg_batch_number')]);
        Flash::success('Work order saved.');
        redirect('/work-orders/' . $p['id']);
    }

    public function destroy(array $p): void
    {
        Db::call('sp_work_order_delete', [$this->uid(), $p['id']]);
        Flash::success('Work order deleted.');
        redirect('/work-orders');
    }

    public function addJobCard(array $p): void
    {
        $ws = $this->str('workstation_id');
        if ($ws === '') {
            throw new AppError('Choose a workstation.');
        }
        Db::callOut('sp_job_card_create', [$this->uid(), $p['id'], $ws, $this->str('assign_me') === '1' ? Auth::id() : null, $this->nul('die_id')]);
        Flash::success('Job card created.');
        redirect('/work-orders/' . $p['id']);
    }

    public function jobCardStatus(array $p): void
    {
        $status = $this->str('status');
        if (!in_array($status, self::JOB_STATUSES, true)) {
            throw new AppError('Choose a valid job card status.');
        }
        Db::call('sp_job_card_set_status', [$this->uid(), $p['id'], $status]);
        Flash::success('Job card ' . strtolower($status) . '.');
        redirect($this->backTo('/shop-floor'));
    }

    public function jobCardDelete(array $p): void
    {
        Db::call('sp_job_card_delete', [$this->uid(), $p['id']]);
        Flash::success('Job card deleted.');
        redirect($this->backTo('/work-orders'));
    }

    private function formData(array $row, array $extra): array
    {
        $items = [];
        foreach (Db::rows('sp_item_list', [$this->uid(), 'FINISHED_GOODS']) as $i) {
            $items[$i['id']] = $i['code'] . ' ' . $i['name'];
        }
        $orders = [];
        foreach (Db::rows('sp_order_list', [$this->uid(), null]) as $o) {
            if (in_array($o['status'], ['CONFIRMED', 'APPROVED', 'IN_PRODUCTION'], true)) {
                $orders[$o['id']] = $o['order_number'] . ' ' . $o['customer_name'];
            }
        }
        return $extra + ['row' => $row, 'items' => $items, 'orders' => $orders];
    }
}
