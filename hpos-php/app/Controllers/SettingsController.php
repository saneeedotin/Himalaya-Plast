<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;

/** Company details and invoicing defaults, kept in the database. */
final class SettingsController extends Controller
{
    public function index(): void
    {
        $this->render('settings/index', ['title' => 'Settings', 'settings' => Db::rows('sp_settings_list', [$this->uid()])]);
    }

    public function update(): void
    {
        $values = (array) ($_POST['setting'] ?? []);
        $changed = 0;
        foreach (Db::rows('sp_settings_list', [$this->uid()]) as $s) {
            $key = $s['setting_key'];
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $new = trim((string) $values[$key]);
            $new = $key === 'company_gstin' ? strtoupper($new) : $new;
            if ($new !== $s['setting_value']) {
                Db::call('sp_settings_set', [$this->uid(), $key, $new]);
                $changed++;
            }
        }
        Flash::success($changed > 0 ? "Saved $changed setting" . ($changed === 1 ? '' : 's') . '.' : 'Nothing changed.');
        redirect('/settings');
    }
}
