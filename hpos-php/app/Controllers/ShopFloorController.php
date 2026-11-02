<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** Tablet-first shop-floor board, production logging and downtime. */
final class ShopFloorController extends Controller
{
    public const REASONS = [
        'MECHANICAL' => 'Mechanical breakdown', 'ELECTRICAL' => 'Electrical fault', 'MATERIAL' => 'Waiting for material',
        'CHANGEOVER' => 'Die or colour change', 'QUALITY' => 'Quality problem', 'POWER' => 'Power cut',
        'CLEANING' => 'Cleaning', 'OTHER' => 'Other',
    ];

    public function board(): void
    {
        $sets = Db::call('sp_shop_floor', [$this->uid()]);
        $open = [];
        foreach (Db::rows('sp_downtime_list', [$this->uid(), 2]) as $d) {
            if ($d['end_time'] === null) {
                $open[$d['workstation_id']] = $d;
            }
        }
        $atMachine = [];
        foreach (Db::rows('sp_machine_stock_levels', [$this->uid()]) as $l) {
            $atMachine[$l['workstation_id']][] = $l;
        }
        $stepsDone = [];
        foreach (Db::rows('sp_job_steps_summary', [$this->uid()]) as $s) {
            $stepsDone[$s['job_card_id']] = (int) $s['steps_done'];
        }
        $this->render('shop_floor/board', [
            'title' => 'Shop floor', 'cards' => $sets[0] ?? [], 'workstations' => $sets[1] ?? [], 'openStops' => $open, 'reasons' => self::REASONS,
            'stepsDone' => $stepsDone, 'atMachine' => $atMachine,
        ]);
    }

    /** Add metres or kg of scrap to a job card. */
    public function log(): void
    {
        $type = $this->str('type');
        $amount = $this->str('amount');
        if (!in_array($type, ['METERS', 'SCRAP'], true)) {
            throw new AppError('Choose metres or scrap.');
        }
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new AppError('Enter an amount above zero.');
        }
        $jc = $this->str('job_card_id');
        if ($jc === '') {
            throw new AppError('No job card selected.');
        }
        Db::call('sp_production_log', [$this->uid(), $jc, $type, $amount]);
        Flash::success($type === 'METERS' ? "Logged +$amount m." : "Logged $amount kg of scrap.");
        redirect($this->backTo('/shop-floor'));
    }

    public function downtime(): void
    {
        $days = (int) ($_GET['days'] ?? 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $this->render('shop_floor/downtime', ['title' => 'Downtime', 'rows' => Db::rows('sp_downtime_list', [$this->uid(), $days]), 'days' => $days, 'reasons' => self::REASONS]);
    }

    public function startStop(): void
    {
        $reason = $this->str('reason_code');
        if (!isset(self::REASONS[$reason])) {
            throw new AppError('Choose why the machine stopped.');
        }
        $ws = $this->str('workstation_id');
        if ($ws === '') {
            throw new AppError('No workstation selected.');
        }
        Db::callOut('sp_downtime_start', [$this->uid(), $ws, $this->nul('job_card_id'), $reason, $this->nul('notes')]);
        Flash::success('Stop logged. The machine is marked as in maintenance.');
        redirect($this->backTo('/shop-floor'));
    }

    public function endStop(array $p): void
    {
        Db::call('sp_downtime_end', [$this->uid(), $p['id']]);
        Flash::success('Machine back in service.');
        redirect($this->backTo('/shop-floor'));
    }
}
