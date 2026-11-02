<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;

/** Receivables (with ageing) and payables. */
final class LedgerController extends Controller
{
    public const BUCKETS = ['NOT_DUE' => 'Not yet due', '1-30' => '1-30 days late', '31-60' => '31-60 days late', '61-90' => '61-90 days late', '90+' => 'Over 90 days late'];

    public function receivables(): void
    {
        $sets = Db::call('sp_receivables', [$this->uid()]);
        $buckets = [];
        foreach ($sets[1] ?? [] as $b) {
            $buckets[$b['aging_bucket']] = $b;
        }
        $this->render('ledgers/receivables', ['title' => 'Receivables', 'rows' => $sets[0] ?? [], 'buckets' => $buckets]);
    }

    public function payables(): void
    {
        $this->render('ledgers/payables', ['title' => 'Payables', 'rows' => Db::rows('sp_payables', [$this->uid()])]);
    }
}
