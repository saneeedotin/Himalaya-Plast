<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;

/**
 * Reorder watch: customers worked out from their own order history as due to reorder, soon, or gone quiet.
 * HPOS suggests; a person presses the button that creates the follow-up.
 */
final class ReorderController extends Controller
{
    public function index(): void
    {
        $rows = Db::rows('sp_reorder_watch', [$this->uid()]);
        $count = ['DUE' => 0, 'QUIET' => 0, 'SOON' => 0];
        foreach ($rows as $r) {
            if (isset($count[$r['reorder_status']])) {
                $count[$r['reorder_status']]++;
            }
        }
        $this->render('reorders/index', ['title' => 'Reorder watch', 'rows' => $rows, 'count' => $count]);
    }

    public function followUp(array $p): void
    {
        Db::callOut('sp_reorder_follow_up', [$this->uid(), $p['id']]);
        Flash::success('Follow-up created, with a reminder for tomorrow.');
        redirect('/customers/' . $p['id']);
    }
}
