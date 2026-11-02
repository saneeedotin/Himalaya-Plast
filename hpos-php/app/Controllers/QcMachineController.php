<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;

/** One QC sheet per machine: each machine's running job, its open sheet and whether a reading is due. */
final class QcMachineController extends Controller
{
    public function index(): void
    {
        $this->render('quality/machines', ['title' => 'QC machines', 'machines' => Db::rows('sp_qc_machine_board', [$this->uid()])]);
    }
}
