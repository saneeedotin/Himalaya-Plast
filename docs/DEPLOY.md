# Deploying HPOS on a PHP and MySQL host

Plain PHP, no Composer, no build step. Everything uploads by FTP or cPanel File Manager.
Business rules live in the database; PHP only signs people in, calls stored procedures and shows the result.

## 1. What the host needs

| Need | Check |
|---|---|
| PHP 8.0 or newer with `pdo_mysql`, `mbstring`, `json` | `php tools/setup.php` or the `/setup` page |
| MySQL 8.0.16+ **or** MariaDB 10.6+ | the same check reports it |
| HTTPS | without it the session cookie cannot carry the Secure flag |
| Apache with `mod_rewrite` (or Nginx with the equivalent) | the `.htaccess` files do the rest |
| Cron | for alerts and backups |
| `mysqldump` for backups | usually present on cPanel hosts |

Tested on PHP 8.3 with MySQL 8.0.45. MariaDB has **not** been run yet: do the steps below on a MariaDB scratch
database first and run `pwsh tools/run_all_tests.ps1` against it before relying on it.

## 2. Create the database and two accounts

Use two database accounts. The app account can only run procedures, so a hole in the PHP cannot read or edit tables.

```sql
CREATE DATABASE hpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- loads the SQL files and takes backups: needs full rights on hpos (cPanel: "all privileges")
CREATE USER 'hpos_admin'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL ON hpos.* TO 'hpos_admin'@'localhost';

-- the app: can only call procedures. Proven by running the whole test suite as this account.
CREATE USER 'hpos_app'@'localhost' IDENTIFIED BY '<another long random password>';
GRANT EXECUTE ON hpos.* TO 'hpos_app'@'localhost';
```

On cPanel create these in MySQL Databases and add each user to the database with the privileges above.

## 3. Load the database

Run the twenty files in order, **as `hpos_admin`** (phpMyAdmin, Adminer or the `mysql` client; the `DELIMITER` lines are handled):

    01_schema  02_views  03_core_procedures  04_masters_orders_production  05_buying_quality_dispatch
    06_accounts_alerts  07_read_procedures  08_triggers  09_seed  10_books  11_books_screens  12_qc_sheets
    13_job_checklist  14_planning  15_customer_insight  16_buying_advice  17_deadline_advice  18_machine_stock
    19_qc_custom_fields  20_today

* Each file starts with `USE hpos;`. Edit that line if your database has another name (cPanel prefixes names, for example `acme_hpos`).
* Binary logging: MySQL 8 refuses to create functions and triggers for an account without the SUPER privilege. If file 03 or 08 stops with
  "You do not have the SUPER privilege and binary logging is enabled", load those files as the host's root/admin account, or ask the host to
  set `log_bin_trust_function_creators=1`.
* Re-running 03 to 08, 10 and 11 is safe (they drop and recreate; 10 only creates the book tables if missing). Do **not** re-run 01 on a database that has data.
* Upgrading later: apply any file in `database/mysql/upgrades/` in date order, after re-running the changed `0x_*.sql` files.
* **Adding the job checklist and the planning board to a database that already has data**: run `13_job_checklist.sql`; apply `upgrades/2026-11-planning.sql` (adds `job_cards.planned_start` and `planned_end`), then run `14_planning.sql`. Re-run `04_masters_orders_production.sql` too if you have not since the no-barcode receiving change (receiving without a barcode, and the mixing-check lot list).
* **Adding the smart parts (files 15 to 20) to a database that already has data**: apply `upgrades/2026-11-buying-advice.sql` (adds `items.lead_time_days`, `reorder_qty`, `preferred_supplier_id`), then run `15` to `20` in order. Re-run `04_masters_orders_production.sql`, `06_accounts_alerts.sql` and `07_read_procedures.sql` first if you have not since this change (04: material taken from the machine's stock and the lot number made on receiving; 06: the new alerts and settings checks; 07: the reorder date on the customer list and page). Files 19 and 12 together replace the QC sheet's fixed columns with fields; 19 copies earlier readings across and can be run again. The suggestions need no setup, but they get better when each raw material has a lead time and each finished product has its limits.
* **Adding the QC sheets to a database that already has data**: apply `upgrades/2026-11-qc-sheets.sql` (adds `items.colour`), then run `12_qc_sheets.sql`. Then set colour and limits on each finished-goods item (Items, the item page).
* **Adding the books to a database that already has data**: apply `upgrades/2026-11-books.sql`, re-run 04, 06, 08, 10 and 11, then run `CALL sp_gl_backfill();` once. It posts every existing invoice, bill and payment into the books (running it again changes nothing). Then enter opening balances under Books, Chart of accounts. Take a backup first.

Then set the administrator's password (the seed creates `admin@himalayaplast.local` with an unusable hash):

    php tools/hash_password.php          # type the password when asked; it prints a hash
    UPDATE users SET password_hash = '<the hash>' WHERE email = 'admin@himalayaplast.local';

## 4. Upload the app

Upload `hpos-php/` and make **`public/` the document root**. If the host will not let you, leave the folder as is:
the `.htaccess` in its root sends every request into `public/`, and `app/`, `config/` and `tools/` are blocked either way.

Create the config from the example and fill in the `db` section with the **`hpos_app`** account:

    cp config/config.example.php config/config.php

Keep `config.php` outside the web root if you can, and point the `HPOS_CONFIG` environment variable at it.
Leave `debug` as `false`. Make sure the folder PHP uses for sessions is writable (the server check tells you).

## 5. Check the server

Open `https://your-host/setup` (or run `php tools/setup.php`). Every line should be OK; a WARN for mod_rewrite is fine under the
command line. Fix any FAIL. When it is green, set `setup_enabled` to `false` in `config.php`.

Sign in as the administrator, then:

1. **Settings** (Admin menu): company name, **GSTIN**, state code, default tax rate, default credit days. Invoices print these.
2. **Users**: create each person with the right role. Roles and what they may do are in [PERMISSIONS.md](PERMISSIONS.md).
3. Change the administrator password if you set a temporary one.
4. Load master data: screens, or CSV (section 8).

## 6. Scheduled jobs

Two cron entries (cPanel: Cron Jobs). Use the PHP binary the host gives you.

    */10 * * * *  php /home/you/hpos-php/tools/cron.php
    30 2 * * *    php /home/you/hpos-php/tools/backup.php --dir=/home/you/backups --keep=30

`cron.php` raises the standing alerts (low stock, orders due, QC waiting, overdue receivables), sends any Web Push that is
waiting, and clears old sessions. Event alerts (an order confirmed, a machine stopped, a QC reject, a payment, goods received)
are raised the moment they happen, and the alert check also runs by itself every ten minutes while anyone has HPOS open, so
alerts work before cron is set up; cron is what keeps them current when nobody is using it. If the host allows
MySQL events you can use those instead (commented at the end of `06_accounts_alerts.sql`).

`backup.php` needs a database account that may read everything: put `'backup' => ['user' => 'hpos_admin', 'pass' => '...']` in
`config.php`. **Copy the backups off the server** (download, or sync to another machine); a backup on the same disk does not survive the disk.

## 6a. Notifications and push

Each person sees the alerts that fit their role (the read right on the resources an alert names), and reads them separately.
The bell and pop-ups update about every 20 seconds while HPOS is open.

**Web Push** reaches people with HPOS closed. It needs:

- **HTTPS** on the live site (browsers refuse push on plain HTTP; `localhost` is allowed for testing).
- **PHP openssl** (already required), and outgoing HTTPS from the server to the browsers' push services, through PHP itself or the
  `curl` program.
- A **VAPID key pair**, made automatically the first time push is used and saved in `config/vapid.php` (git-ignored). Back it up
  with the rest of `config/`: if it is lost, everyone must turn push on again. To use your own, set
  `'push' => ['public' => '...', 'private_pem' => '...', 'subject' => 'mailto:you@example.com']` in `config.php`, with a real
  contact address as the subject.
- The `config/` folder writable by the web user for that first run, or the key pair put in `config.php` instead.

Each person turns it on once per browser: **Notifications, Turn on for this browser**, then **Send me a test**. The server only
sends to the browsers' own push services (Google, Mozilla, Microsoft, Apple); any other address is refused. Run
`php tools/push_selftest.php` to check the cryptography on the server (it needs no network).

## 7. Backup and restore, and the drill

    php tools/restore.php /home/you/backups/hpos-20261101-023000.sql.gz --database=hpos_restore

Restore always goes into a **new, empty database**; it refuses to touch the live one. Check it (sign in with `HPOS_CONFIG` pointing at it,
or compare counts), then switch `config.php` to the new database. The restore drops `DEFINER` clauses so it works under a different database
user or on another host. Do this drill once before go-live and write down how long it took.

## 8. Loading existing data

    php tools/import_csv.php customers customers.csv            # dry run: checks every row, changes nothing
    php tools/import_csv.php customers customers.csv --commit
    php tools/import_csv.php items items.csv --commit --update  # also update rows that already exist

Sample files with the columns are in `docs/import-samples/`. Only customers, suppliers and items are importable (masters).
Opening balances, stock and open orders are not imported on purpose: enter stock with Receive material or a purchase order, so the ledger
shows where every kilogram came from.

## 9. Go-live checklist

- [ ] `php tools/setup.php` is all OK; `setup_enabled` is `false`; `debug` is `false`
- [ ] The app connects as the EXECUTE-only account
- [ ] HTTPS works, and the sign-in page is only reachable over it
- [ ] Administrator password is not the temporary one; each person has their own account and the right role
- [ ] Settings: company name, GSTIN, state code
- [ ] A test order has gone all the way: order, confirm, produce, inspect, pack, scan, invoice, payment (delete test records afterwards, or do it on the scratch database)
- [ ] Cron lines are in place and `tools/cron.php` ran once by hand without errors
- [ ] A backup was made, copied off the server, and restored into a spare database
- [ ] Barcode scanners are set to send Enter after each scan, and one carton label scans correctly

## 10. When something is wrong

| Sign | Likely cause |
|---|---|
| "Something went wrong" page | see the PHP error log; set `debug` to `true` for a moment on a test host to see the message |
| Blank styling | the document root is not `public/`, or `mod_rewrite` is off |
| "Forbidden" on a page | the user's role lacks that right; check [PERMISSIONS.md](PERMISSIONS.md) |
| Cannot sign in, "locked" | five wrong passwords lock the account for 15 minutes; an administrator can set a new password to unlock it at once |
| Alerts never appear | open Notifications once (it runs the check), or run `php tools/cron.php` by hand; if cron is set up, check that it runs |
| Push: Turn on does nothing, or says it is not set up | the page is not on HTTPS (or localhost), or PHP openssl cannot make keys: run `php tools/push_selftest.php` |
| Push: Send me a test cannot reach the push service | the server cannot make outgoing HTTPS requests (firewall), or PHP cannot verify certificates: set `openssl.cafile` in php.ini, or install the `curl` program |
| `/setup` shows FAIL on JSON_TABLE | the database is too old (MySQL before 8.0.16, MariaDB before 10.6) |
