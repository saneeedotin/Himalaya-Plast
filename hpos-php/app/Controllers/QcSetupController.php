<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;

/** The QC sheet setup: what is measured and what the header and column heads are called. The client shapes it. */
final class QcSetupController extends Controller
{
    public const KINDS = ['NUMBER' => 'A number (a size, a weight...)', 'CHOICE' => 'A choice (OK or not OK...)', 'TEXT' => 'Free text'];

    public function index(): void
    {
        $this->render('quality/setup', ['title' => 'QC sheet setup', 'fields' => Db::rows('sp_qc_field_list', [$this->uid()]), 'labels' => Db::rows('sp_qc_labels_list', [$this->uid()]), 'kinds' => self::KINDS]);
    }

    /** Add (no id) or change one measured field. */
    public function saveField(): void
    {
        $kind = $this->str('kind');
        $sort = $this->str('sort_no');
        if ($sort !== '' && !preg_match('/^-?\d{1,4}$/', $sort)) {
            throw new AppError('The order is a whole number.');
        }
        Db::callOut('sp_qc_field_save', [
            $this->uid(), $this->nul('id'), $this->str('code'), $this->str('label'), $kind ?: 'NUMBER', $this->nul('unit'), $this->nul('choices'), $this->nul('bad_values'),
            $this->str('has_limits') === '1' ? 1 : 0, $sort === '' ? null : (int) $sort, $this->str('is_active') === '1' ? 1 : 0,
        ]);
        Flash::success($this->str('id') === '' ? 'Field added to the sheet.' : 'Field saved.');
        redirect('/quality/setup');
    }

    /** All header and column labels at once: label[KEY] and visible[KEY]. */
    public function saveLabels(): void
    {
        $labels = (array) ($_POST['label'] ?? []);
        $visible = (array) ($_POST['visible'] ?? []);
        foreach ($labels as $key => $text) {
            Db::call('sp_qc_label_save', [$this->uid(), (string) $key, trim((string) $text), isset($visible[$key]) ? 1 : 0]);
        }
        Flash::success('Labels saved. The sheet, its print and the CSV use them now.');
        redirect('/quality/setup');
    }
}
