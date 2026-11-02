<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;

/** One search box across orders, invoices, customers, suppliers, items, batches, purchase orders, work orders and staff. */
final class SearchController extends Controller
{
    private const LABELS = [
        'ORDER' => 'Sales orders', 'INVOICE' => 'Invoices', 'CUSTOMER' => 'Customers', 'SUPPLIER' => 'Suppliers',
        'ITEM' => 'Items', 'BATCH' => 'Batches', 'USER' => 'Staff', 'PO' => 'Purchase orders', 'WORK ORDER' => 'Work orders',
    ];

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = [];
        $message = null;
        if ($q !== '') {
            try {
                $results = Db::rows('sp_search', [$this->uid(), mb_substr($q, 0, 100)]);
            } catch (AppError $e) {
                if ($e->isForbidden()) {
                    throw $e;
                }
                $message = $e->getMessage();
            }
        }
        $groups = [];
        foreach ($results as $r) {
            $groups[$r['kind']][] = $r;
        }
        $this->render('search/index', ['title' => 'Search', 'q' => $q, 'groups' => $groups, 'message' => $message, 'count' => count($results), 'labels' => self::LABELS]);
    }

    /** JSON for the header's search-as-you-type: a few matches per kind, only what this user may read. */
    public function suggest(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $out = ['q' => $q, 'groups' => [], 'message' => null];
        try {
            $rows = Db::rows('sp_search_suggest', [$this->uid(), mb_substr($q, 0, 100)]);
            $by = [];
            foreach ($rows as $r) {
                $by[$r['kind']][] = ['title' => (string) $r['title'], 'subtitle' => (string) $r['subtitle'], 'url' => url($r['url'])];
            }
            foreach (self::LABELS as $kind => $label) {
                if (isset($by[$kind])) {
                    $out['groups'][] = ['label' => $label, 'items' => $by[$kind]];
                }
            }
        } catch (AppError $e) {
            if ($e->isForbidden()) {
                http_response_code(403);
            }
            $out['message'] = $e->getMessage();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
