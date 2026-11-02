<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Csv;
use App\Core\Db;

/** Accounting exports: Book Keeper CSV and a TallyPrime XML voucher. The database returns rows; this formats them. */
final class ExportController extends Controller
{
    public function index(): void
    {
        $orders = array_values(array_filter(
            Db::rows('sp_order_list', [$this->uid(), null]),
            static fn (array $o): bool => !in_array($o['status'], ['DRAFT', 'PROFORMA_SENT', 'CANCELLED'], true)
        ));
        $this->render('exports/index', ['title' => 'Accounting exports', 'orders' => $orders]);
    }

    public function bookkeeper(): void
    {
        $order = $this->orderId();
        $type = ($_GET['type'] ?? '') === 'MATERIAL_CONSUMPTION' ? 'MATERIAL_CONSUMPTION' : 'SALES_INVOICE';
        $rows = Db::rows('sp_export_bookkeeper', [$this->uid(), $order, $type]);
        if (!$rows) {
            throw new AppError($type === 'MATERIAL_CONSUMPTION' ? 'No raw material has been consumed for this order yet.' : 'This order has no lines to export.');
        }
        $header = array_keys($rows[0]);
        $label = $rows[0]['voucher_no'] ?? $order;
        Csv::send(strtolower($type) . '_' . $label . '.csv', $header, array_map('array_values', $rows));
    }

    public function tally(): void
    {
        $order = $this->orderId();
        $rows = Db::rows('sp_export_tally', [$this->uid(), $order, null]);
        if (!$rows) {
            throw new AppError('This order has no lines to export.');
        }
        // one row per item per delivery note: keep each distinct line once
        $lines = [];
        foreach ($rows as $r) {
            $lines[$r['stock_item_name'] . '|' . $r['qty'] . '|' . $r['rate']] = $r;
        }
        $lines = array_values($lines);
        $first = $lines[0];
        $company = 'Himalaya Plast';
        foreach (Db::rows('sp_settings_list', [$this->uid()]) as $s) {
            if ($s['setting_key'] === 'company_name' && $s['setting_value'] !== '') {
                $company = $s['setting_value'];
            }
        }
        $xml = $this->tallyXml($company, $first, $lines);

        try {
            Db::call('sp_tally_sync_log', [$this->uid(), 'SALES_ORDER', $order, $xml, 'EXPORTED', null]);
        } catch (\Throwable $e) {
            error_log('[hpos] tally log failed: ' . $e->getMessage());
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tally_' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $first['voucher_no']) . '.xml"');
        echo $xml;
        exit;
    }

    private function orderId(): string
    {
        $id = (string) ($_GET['order'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            throw new AppError('Choose an order.');
        }
        return $id;
    }

    /**
     * Sales voucher import for TallyPrime. Amounts are the taxable value; post GST ledgers in Tally,
     * or extend this with your tax ledger names once you have them.
     *
     * @param list<array<string,mixed>> $lines
     */
    private function tallyXml(string $company, array $first, array $lines): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('ENVELOPE');
        $w->startElement('HEADER');
        $w->writeElement('TALLYREQUEST', 'Import Data');
        $w->endElement();
        $w->startElement('BODY');
        $w->startElement('IMPORTDATA');
        $w->startElement('REQUESTDESC');
        $w->writeElement('REPORTNAME', 'Vouchers');
        $w->startElement('STATICVARIABLES');
        $w->writeElement('SVCURRENTCOMPANY', $company);
        $w->endElement();
        $w->endElement();
        $w->startElement('REQUESTDATA');
        $w->startElement('TALLYMESSAGE');
        $w->writeAttribute('xmlns:UDF', 'TallyUDF');
        $w->startElement('VOUCHER');
        $w->writeAttribute('VCHTYPE', 'Sales');
        $w->writeAttribute('ACTION', 'Create');
        $w->writeAttribute('OBJVIEW', 'Invoice Voucher View');
        $w->writeElement('DATE', date('Ymd'));
        $w->writeElement('VOUCHERTYPENAME', 'Sales');
        $w->writeElement('VOUCHERNUMBER', (string) $first['voucher_no']);
        $w->writeElement('REFERENCE', (string) $first['order_number']);
        $w->writeElement('PARTYLEDGERNAME', (string) $first['party_name']);
        $w->writeElement('PERSISTEDVIEW', 'Invoice Voucher View');
        $total = 0.0;
        foreach ($lines as $l) {
            $total += (float) $l['amount'];
        }
        $w->startElement('ALLLEDGERENTRIES.LIST');
        $w->writeElement('LEDGERNAME', (string) $first['party_name']);
        $w->writeElement('ISDEEMEDPOSITIVE', 'Yes');
        $w->writeElement('ISPARTYLEDGER', 'Yes');
        $w->writeElement('AMOUNT', number_format(-$total, 2, '.', ''));
        $w->endElement();
        foreach ($lines as $l) {
            $w->startElement('ALLINVENTORYENTRIES.LIST');
            $w->writeElement('STOCKITEMNAME', (string) $l['stock_item_name']);
            $w->writeElement('ISDEEMEDPOSITIVE', 'No');
            $w->writeElement('RATE', number_format((float) $l['rate'], 2, '.', '') . '/' . $l['uom']);
            $w->writeElement('AMOUNT', number_format((float) $l['amount'], 2, '.', ''));
            $w->writeElement('ACTUALQTY', trim_zeros($l['qty']) . ' ' . $l['uom']);
            $w->writeElement('BILLEDQTY', trim_zeros($l['qty']) . ' ' . $l['uom']);
            $w->startElement('ACCOUNTINGALLOCATIONS.LIST');
            $w->writeElement('LEDGERNAME', 'Sales');
            $w->writeElement('ISDEEMEDPOSITIVE', 'No');
            $w->writeElement('AMOUNT', number_format((float) $l['amount'], 2, '.', ''));
            $w->endElement();
            $w->endElement();
        }
        $w->endElement(); // VOUCHER
        $w->endElement(); // TALLYMESSAGE
        $w->endElement(); // REQUESTDATA
        $w->endElement(); // IMPORTDATA
        $w->endElement(); // BODY
        $w->endElement(); // ENVELOPE
        $w->endDocument();
        return $w->outputMemory();
    }
}
