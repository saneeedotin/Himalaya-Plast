<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/**
 * The production planning board: machines down the side, days across the top, each planned job card a bar.
 * Planning is advice for the floor. An overlap on one machine is shown and warned about, never refused.
 */
final class PlanningController extends Controller
{
    public const DAYS = 14;

    public function board(): void
    {
        $monday = $this->monday($this->dateParam('from', date('Y-m-d')));
        $sets = Db::call('sp_plan_board', [$this->uid(), $monday, self::DAYS]);
        $today = date('Y-m-d');
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $d = strtotime("$monday +$i day");
            $days[] = ['date' => date('Y-m-d', $d), 'dow' => date('D', $d), 'day' => date('d', $d), 'weekend' => (int) date('N', $d) >= 6, 'today' => date('Y-m-d', $d) === $today];
        }
        $byMachine = [];
        foreach ($sets[1] ?? [] as $j) {
            $byMachine[$j['workstation_id']][] = $j;
        }
        $rows = [];
        foreach ($sets[0] ?? [] as $ws) {
            $rows[] = ['ws' => $ws, 'lanes' => $this->lanes($byMachine[$ws['id']] ?? [], $monday, $today)];
        }
        $this->render('planning/board', [
            'title' => 'Planning board', 'days' => $days, 'rows' => $rows, 'unplanned' => $sets[2] ?? [], 'from' => $monday,
            'prev' => date('Y-m-d', strtotime("$monday -7 day")), 'next' => date('Y-m-d', strtotime("$monday +7 day")),
            'thisWeek' => $this->monday($today), 'today' => $today,
        ]);
    }

    public function edit(array $p): void
    {
        $this->render('planning/form', $this->formData($p['id'], null));
    }

    public function save(array $p): void
    {
        $row = ['workstation_id' => $this->str('workstation_id'), 'planned_start' => $this->str('planned_start'), 'planned_end' => $this->str('planned_end')];
        $this->saving(function () use ($p, $row) {
            foreach (['planned_start', 'planned_end'] as $k) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$k]) || strtotime($row[$k]) === false) {
                    throw new AppError('Give both a start and an end date.');
                }
            }
            $res = Db::row('sp_job_card_plan', [$this->uid(), $p['id'], $row['workstation_id'] ?: null, $row['planned_start'], $row['planned_end']]);
            if ((int) ($res['clashes'] ?? 0) > 0) {
                Flash::error('Planned, but it overlaps ' . $res['clash_list'] . ' on the same machine.');
            } else {
                Flash::success('Planned.');
            }
            redirect('/planning?from=' . $row['planned_start']);
        }, 'planning/form', $this->formData($p['id'], $row));
    }

    public function clear(array $p): void
    {
        Db::call('sp_job_card_unplan', [$this->uid(), $p['id']]);
        Flash::success('Plan removed. The job is in the unplanned list again.');
        redirect('/planning');
    }

    /**
     * Lays one machine's jobs into rows that do not overlap. Each row is a list of cells to draw across the window:
     * ['gap', days] for empty days and ['bar', days, job, cutBefore, cutAfter] for a job.
     *
     * @return list<list<array>>
     */
    private function lanes(array $jobs, string $from, string $today): array
    {
        $startTs = strtotime($from);
        $last = self::DAYS - 1;
        $lanes = [];
        foreach ($jobs as $j) {
            $s = max(0, (int) round((strtotime((string) $j['planned_start']) - $startTs) / 86400));
            $e = min($last, (int) round((strtotime((string) $j['planned_end']) - $startTs) / 86400));
            $j['late'] = $j['status'] !== 'COMPLETED' && (string) $j['planned_end'] < $today;
            $bar = ['s' => $s, 'e' => $e, 'job' => $j, 'before' => (string) $j['planned_start'] < $from, 'after' => (int) round((strtotime((string) $j['planned_end']) - $startTs) / 86400) > $last];
            $placed = false;
            foreach ($lanes as &$lane) {
                if ($lane[count($lane) - 1]['e'] < $s) {
                    $lane[] = $bar;
                    $placed = true;
                    break;
                }
            }
            unset($lane);
            if (!$placed) {
                $lanes[] = [$bar];
            }
        }
        $out = [];
        foreach ($lanes ?: [[]] as $lane) {
            $cells = [];
            $at = 0;
            foreach ($lane as $b) {
                if ($b['s'] > $at) {
                    $cells[] = ['gap', $b['s'] - $at];
                }
                $cells[] = ['bar', $b['e'] - $b['s'] + 1, $b['job'], $b['before'], $b['after']];
                $at = $b['e'] + 1;
            }
            if ($at < self::DAYS) {
                $cells[] = ['gap', self::DAYS - $at];
            }
            $out[] = $cells;
        }
        return $out;
    }

    private function monday(string $date): string
    {
        $t = strtotime($date);
        return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' day', $t));
    }

    private function formData(string $id, ?array $row): array
    {
        $sets = Db::call('sp_job_card_plan_get', [$this->uid(), $id]);
        $job = $this->must($sets[0][0] ?? null);
        $machines = [];
        foreach ($sets[1] ?? [] as $w) {
            $machines[$w['id']] = $w['code'] . ' ' . $w['name'] . ($w['status'] === 'MAINTENANCE' ? ' (maintenance)' : '');
        }
        $start = $job['planned_start'] ? substr((string) $job['planned_start'], 0, 10) : date('Y-m-d');
        $row ??= ['workstation_id' => $job['current_workstation_id'], 'planned_start' => $start,
            'planned_end' => $job['planned_end'] ? substr((string) $job['planned_end'], 0, 10) : date('Y-m-d', strtotime("$start +2 day"))];
        return ['title' => 'Plan ' . $job['work_order_number'], 'job' => $job, 'machines' => $machines, 'row' => $row];
    }
}
