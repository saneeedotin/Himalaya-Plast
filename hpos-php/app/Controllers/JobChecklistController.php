<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** The checklist an operator works through before and as a job starts. It guides; it does not block. */
final class JobChecklistController extends Controller
{
    /** key => [label, can be marked "not needed", hint] */
    public const STEPS = [
        'MATERIAL_VERIFY' => ['Material verification', false, 'The right raw material, lot and colour are at the machine.'],
        'MATERIAL_CHANGE' => ['Material change', true, 'If the last job ran a different material, flush and change it.'],
        'HOPPER_LOADING' => ['Hopper loading', false, 'Material loaded and mixed as the BOM says (see the mixing check).'],
        'DIE_CHANGE' => ['Die change', true, 'If this job needs a different die, fit and check it.'],
        'AIR_PRESSURE' => ['Air pressure check', false, 'Note the pressure reading, in bar.'],
        'TRIAL_PRODUCTION' => ['Trial production', false, 'A short trial run to settle the line.'],
        'FIRST_QUALITY' => ['First quality verification', false, 'First pieces checked against the sizes (start a QC sheet).'],
        'PRODUCTION_STARTED' => ['Production started', false, 'Running at full output.'],
    ];

    public function show(array $p): void
    {
        $sets = Db::call('sp_job_steps_get', [$this->uid(), $p['id']]);
        $job = $this->must($sets[0][0] ?? null);
        $done = [];
        foreach ($sets[1] ?? [] as $s) {
            $done[$s['step_key']] = $s;
        }
        $this->render('checklist/show', ['title' => 'Checklist ' . $job['workstation_code'], 'job' => $job, 'steps' => self::STEPS, 'done' => $done]);
    }

    public function set(array $p): void
    {
        $step = $this->str('step');
        $action = $this->str('action');
        $reading = $this->str('reading');
        if ($reading !== '' && (!is_numeric($reading) || (float) $reading < 0)) {
            throw new AppError('The air pressure must be a number, zero or more.');
        }
        Db::call('sp_job_step_set', [$this->uid(), $p['id'], $step, $action, $reading === '' ? null : $reading, $this->nul('note')]);
        Flash::success($action === 'RESET' ? 'Step undone.' : 'Step saved.');
        redirect('/job-cards/' . $p['id'] . '/checklist');
    }
}
