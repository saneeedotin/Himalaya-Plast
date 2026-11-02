<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\View;

/** Home page: headline numbers for people who may see the dashboard, a welcome for everyone else. */
final class HomeController
{
    public function index(): void
    {
        $kpi = can('Dashboard', 'read') ? Db::row('sp_dashboard', [Auth::id()]) : null;
        $alerts = array_slice(array_values(array_filter(
            Db::rows('sp_notification_list', [Auth::id(), 8]),
            static fn (array $n): bool => $n['status'] === 'UNREAD'
        )), 0, 5);
        // what to do today: suggestions only, each linking to the screen where a person acts
        View::render('home', ['title' => 'Home', 'user' => Auth::user(), 'kpi' => $kpi, 'alerts' => $alerts, 'todo' => Db::rows('sp_today_suggestions', [Auth::id()])]);
    }
}
