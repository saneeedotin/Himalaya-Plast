<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;

/** Deadlines: every open order against when production can realistically finish. Advice only. */
final class DeadlineController extends Controller
{
    public function index(): void
    {
        $rows = Db::rows('sp_order_health_list', [$this->uid()]);
        $count = ['LATE' => 0, 'AT_RISK' => 0, 'ON_TRACK' => 0, 'READY' => 0];
        foreach ($rows as $r) {
            $count[$r['health']] = ($count[$r['health']] ?? 0) + 1;
        }
        $show = (string) ($_GET['show'] ?? 'act');
        if ($show !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['health'], ['LATE', 'AT_RISK'], true)));
        }
        $this->render('deadlines/index', ['title' => 'Deadlines', 'rows' => $rows, 'count' => $count, 'show' => $show === 'all' ? 'all' : 'act']);
    }
}
