# HPOS backend in MySQL / MariaDB

All business logic of the Himalaya Plast ERP lives in the database. A PHP front end only
authenticates, calls stored procedures and renders results.

**Status: loads cleanly on MySQL 8.0.45** (first run fixed three syntax errors: reserved word
`last_value` renamed `last_no`; `CONCAT()` inside `SIGNAL MESSAGE_TEXT` and `LIMIT IFNULL()` replaced
with variables). `hpos-php/tools/scenario_test.php` runs the whole chain (buy stock, order, hard gate, produce, QC,
pack and scan, invoice, pay) plus the failure cases (oversell, negative stock, double scan,
overpayment, append-only ledger, permissions): 70 checks, all passing. It found two gaps, now fixed:
08 had no triggers computing line amounts, tax and order totals (invoices came out as 0.00), and
nothing created the finished-goods batch that QC and packing need (`sp_production_log` does now).
MariaDB 10.6+ has not been tried. Read procedures (07), alerts and Tally export are not yet covered.

On a server with binary logging on (MySQL 8 default), files 03-08 must be loaded by an account with
SUPER (e.g. root), or set `log_bin_trust_function_creators=1`.

## Install
Requires MySQL 8.0.16+ or MariaDB 10.6+ (JSON_TABLE, CHECK constraints, `DEFAULT (UUID())`).

    mysql -u root -p < 01_schema.sql
    mysql -u root -p hpos < 02_views.sql
    ... 03, 04, 05, 06, 07, 08, 09, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 in order

    CREATE USER 'hpos_app'@'localhost' IDENTIFIED BY '...';
    GRANT EXECUTE ON hpos.* TO 'hpos_app'@'localhost';   -- all the app needs; no SELECT is required

Then set the admin password (09_seed.sql creates `admin@himalayaplast.local` with an unusable hash):

    php -r "echo password_hash('NewPassword', PASSWORD_ARGON2ID);"
    UPDATE users SET password_hash='<hash>' WHERE email='admin@himalayaplast.local';

Also set `system_settings.company_gstin` (and `company_state_code`, default 24) before invoicing.

## Files
| File | Contents |
|---|---|
| 01_schema | ~43 tables, constraints, indexes, default settings |
| 02_views | stock balance/reserved, material check, order summary, receivables/payables, KPIs, export views |
| 03_core_procedures | doc numbering, RBAC (`fn_can`, `sp_require_permission`), auth, users, audit |
| 04_masters_orders_production | masters, sales orders (confirm = hard material gate + reservations + auto work orders), work orders, job cards, production log, downtime, handovers, scanning |
| 05_buying_quality_dispatch | POs, GRN, QC, delivery notes, carton scan dispatch |
| 06_accounts_alerts | sales/purchase invoices (GST), payments, Tally log, alerts |
| 07_read_procedures | permission-checked read models for every screen, global search |
| 08_triggers | append-only stock ledger and audit log, locked posted invoices |
| 09_seed | roles, permissions, first admin |
| 10_books | the books: chart of accounts, append-only double-entry vouchers and entries, credit/debit notes, and the triggers that post every invoice, bill, payment and note by themselves |
| 11_books_screens | what the Books screens call: accounts, manual vouchers, opening balances, day book, ledgers, trial balance, profit and loss, balance sheet, GST registers, party statements, credit/debit note procedures |
| 12_qc_sheets | the production QC sheet: tolerances per product (`qc_specs`), sheets per batch run, append-only signed readings flagged against the limits, and the procedures behind the QC sheet screens |
| 13_job_checklist | the pre-run checklist on a job card: eight steps, who and when, shown as "n of 8" on the shop floor |
| 14_planning | the planning board: planned dates on job cards (`job_cards.planned_start` / `planned_end`), the machines-by-days board, overlap report, unplanned list |
| 15_customer_insight | repeat-order tracking (usual gap between a customer's orders, expected next order, Due / Soon / Quiet), the Reorder watch list with its confirm-to-create follow-up, customer leads, and the alerts for them |
| 16_buying_advice | raw-material buying advice: usage per day, days of stock left, what is already on order, a suggested quantity, and a confirm-to-create draft purchase order per supplier |
| 17_deadline_advice | deadline awareness: output rate, projected finish and health (On track / At risk / Late) for each open order, suggested delivery date, and the due-soon and stuck-order alerts |
| 18_machine_stock | material kept at each machine: issue to and return from a machine, what a machine has used, hours left, and the low-material alert |
| 19_qc_custom_fields | the QC sheet the client shapes: what is measured (`qc_fields`), the header words (`qc_labels`), readings per field (`qc_sheet_values`), one sheet per machine, the machine board and the due-a-reading alert. Replaces 12's sheet procedures; load after 12. Copies earlier readings across, safe to run again |
| 20_today | "What to do today": one list per person built from the parts above (`sp_today_suggestions`), showing only what the person may open |

Files 15 to 20 only suggest. Nothing is ordered, sent or created until a person confirms it on its screen. Tunable numbers (`reorder_min_orders`, `reorder_soon_days`, `cover_days`, `due_warning_days`, `buffer_days`, `default_metres_per_day`, `issue_lot_sacks`, `machine_low_hours`, `qc_reading_minutes`) are rows in `system_settings`, editable on the Settings screen.

## Calling convention (PHP / PDO)
Every procedure takes the acting user id first (`p_actor`); permission is checked inside the DB.

    $st = $pdo->prepare('CALL sp_order_detail(?, ?)');
    $st->execute([$userId, $orderId]);
    do { $sets[] = $st->fetchAll(PDO::FETCH_ASSOC); } while ($st->nextRowset());

* Errors arrive as `PDOException` with SQLSTATE `45000` (`45001` = already scanned). Show `getMessage()` to the user.
* Use `PDO::ATTR_EMULATE_PREPARES => false` and `ERRMODE_EXCEPTION`.
* Array inputs (order lines, BOM items, GRN lines) are JSON strings parsed with `JSON_TABLE`.
* Login: `sp_auth_get_user_by_email` -> `password_verify` in PHP -> `sp_auth_login_success` / `sp_auth_login_failed`.
  Generate a random 32-byte token, store only its SHA-256 (`sp_auth_get_session` renews the 8 h sliding window).
* Scheduled work: enable the commented `EVENT` blocks at the end of 06 (or cron `CALL sp_generate_alerts()` and `sp_auth_purge_sessions()`).

## Key rules
* Stock is the signed sum of the append-only ledger. Plus: RECEIPT, PRODUCTION_OUTPUT, PRODUCTION, RECOVERY, ADJUSTMENT_IN. Minus: CONSUMPTION, SCRAP, DISPATCH, ADJUSTMENT_OUT.
* Required raw material = qty / output_qty x qty_per_unit / (1 - scrap_factor). Confirming an order locks rows and fails if available stock (physical minus others' reservations) is short.
* Documents are numbered gap-free via `doc_sequences`.
* GST: CGST+SGST when buyer GSTIN state equals `company_state_code`, else IGST.
* RBAC: roles -> permissions (`resource`,`action`, `*` wildcard). Names compare case-insensitively.
* Lockout: 5 failed logins = 15 minutes.
* Books (10, 11): double entry. A voucher's debits equal its credits; vouchers and entries are never updated or deleted (triggers refuse), and a cancel posts a reversing voucher. Posting a sales invoice, bill, payment or credit/debit note writes its voucher in the same transaction, and cancelling reverses it. Sundry Debtors and Sundry Creditors lines carry the customer or supplier. Stock is not in the ledger: it is valued at standard cost from the stock ledger (periodic: opening stock + purchases - closing stock), and stock entered as opening stock is capital, not profit. Financial year is April to March.
* Upgrading an existing database to the books: apply `upgrades/2026-11-books.sql`, re-run 04, 06, 08, 10 and 11, then `CALL sp_gl_backfill();` (idempotent) to post the documents that already exist.

## Differences from the Node app
Race-free numbering and reservations (the old count()+1 and check-then-act patterns are gone), permission names no longer case-sensitive, negative stock blocked on production logging, posted invoices immutable, ledger append-only.

## Not covered
* **The UI.** The Next.js screens must be rewritten in PHP (or plain HTML/JS) calling these procedures. This is the larger remaining job.
* PDF/XML/CSV rendering (invoice print, Tally XML, Book Keeper CSV). The procedures return the rows; PHP formats them.
* Energy/OEE screens (no table existed), the Laravel stock service (superseded by these procedures), and data migration from the existing Postgres/SQLite data.
