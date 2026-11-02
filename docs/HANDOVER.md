# HPOS PHP front end: what was built, how it was checked, what is left

Server-rendered PHP 8 over a MySQL database whose stored procedures hold the business rules.
No framework, no Composer. See [DEPLOY.md](DEPLOY.md) to install it and [PERMISSIONS.md](PERMISSIONS.md) for who can do what.

## What is in it

| Phase | Screens |
|---|---|
| 0 Foundation | server check, sign in and out, my profile (name, phone, password, light/dark theme, recent sign-ins), layout with a menu filtered by permission, error pages |
| 1 Admin and masters | users and roles (with admin password reset), customers with follow-ups, suppliers and leads, items, BOMs, workstations, dies |
| 2 Sales cockpit | orders (list, create, edit), raw-material check, **confirm (hard gate)**, auto work orders, shortage to purchase order, proforma, cancel |
| 3 Shop floor | work orders, job cards, tablet board with big buttons (+100/+500/+1000 m, custom metres, scrap), machine stop and restart, downtime history, shift handovers, scanner screens for receiving a raw-material bag and the two-lot mixing check |
| 4 Buying, quality, dispatch | purchase orders, goods receipts, inspections (QC template pre-fills measurements, scrap writes a batch off), packing into serial cartons, **printable Code 39 labels**, delivery note print, dock-gate scan with supervisor re-scan |
| 5 Accounts | sales invoices with GST (CGST+SGST or IGST by state), post/lock, IRN and e-way bill, print with amount in words (save as PDF from the browser), supplier bills, payments (draft then post, cancel), receivables with ageing, payables, Book Keeper CSV, TallyPrime XML, settings |
| 6 Dashboard and extras | KPI tiles, **notifications** (per person and per role, raised the moment things happen: order confirmed, cancelled or dispatched, machine stopped, QC reject, stock low, payment, goods received; live pop-ups; **Web Push** to browsers that turn it on), global search (only what you may read), stock position, append-only stock ledger, batches, **stock adjustment** (opening stock, count corrections, damage; leaves a ledger entry and a BATCH-ADJ lot) |
| 8 Books | double-entry books filled by every invoice, bill, payment and credit/debit note automatically; cash, bank, cheque and wallet accounts; expense, contra, receipt, payment and journal vouchers; opening balances; day book, ledger, cash and bank book, trial balance, profit and loss, balance sheet, GST summary, sales and purchase registers, HSN summary, party statements (CSV for each) |
| 9 QC sheets | the client's paper QC sheet on screen: start from a job card, a reading about every half hour (pin, width, leg, weight, fitting) signed by whoever enters it, flagged and alerted when outside the product's limits, void instead of edit, close, print like the paper form, CSV in their column order; colour and limits are set on the item |
| 10 Job checklist | a checklist on every job card: material verification, material change, hopper loading, die change, air pressure (with the reading in bar), trial production, first quality verification, production started; each step shows who ticked it and when; the shop floor shows "n/8"; it guides the operator and does not block starting a job |
| 11 Planning board | machines against the next two weeks, each planned job card a bar (planned, running, done, late); overlaps on one machine are saved with a warning, a queued job can move to another machine, unplanned jobs are listed with a Plan link |
| 12 Repeat orders and leads | works out each customer's usual gap between orders and when the next is due (Due, Soon, Quiet); a Reorder watch list where a person confirms to create the follow-up; the same on the customer page and list; a simple customer leads list (new, contacted, quoted, won, lost) that converts a lead into a customer; alerts for customers due, leads due and reminders due |
| 13 Buying advice | for each raw material: use per day from the real ledger, days left, what is already on purchase orders, lead time, and a suggested quantity; grouped by usual supplier with a confirm button that makes one draft purchase order; materials already on a draft are skipped; the order page says what is on order |
| 14 Deadlines | for every open order: how many metres are left, when the machines will finish them (from the planning board, else from the real output rate), the material wait, a suggested delivery date and a health badge (on track, at risk, late); alerts for orders due soon, orders that will miss their date, and orders stuck waiting on someone |
| 15 Machine stock | the sacks unloaded are issued 50 at a time to a machine (and given back); the machine's own stock is used up as it makes metres, with hours left at its recent rate and an alert when it is nearly out; the Stock page and the shop-floor card show it |
| 16 QC sheet the client shapes | what is measured is set up, not coded: add, rename, reorder or switch off fields (number, choice, free text), the header words and their visibility, extra header fields; limits per product for every limited field; one sheet per machine, a QC machines board that says which running machine is due a reading, and an alert; earlier sheets carry over |
| 17 What to do today | a list on the dashboard of what to do now, worked out from phases 12 to 16 (orders that will miss their date, material to order, machines out of material, QC readings out of range or due, customers to call, leads to follow up) and shown only for the screens each person may open; every line links to where to act |
| 7 Hardening | route audit, CSRF on every POST, security headers, least-privilege database account, backup and restore (verified), CSV import for masters, permission matrix |

## How it was checked

Everything below ran against a real MySQL 8.0.45 database. `pwsh tools/run_all_tests.ps1` runs it all:

* **Stored procedures end to end** (`tools/scenario_test.php`, 70 checks): buy, order, hard gate, produce, inspect, pack, scan, invoice, pay, plus oversell, negative stock, double scan, overpayment, append-only ledger, permissions.
* **More stored-procedure suites**: books (122), QC sheets and setup (119), job checklist (30), planning (35), repeat orders and leads (45), buying advice (37), deadlines (29), machine stock (37), what to do today (32).
* **Real pages** (`tools/smoke_phase1..17.ps1`, 1,000+ checks across 17 scripts): every screen's create, edit, delete and validation paths, and who is refused.
* **Route audit** (`tools/audit_routes.php`): 234 routes; none unprotected; none changes data on GET. Signed out, all 79 protected pages redirect to sign-in. All 74 POST routes refuse a missing or forged CSRF token.
* **Least privilege**: the whole HTTP suite also passes when the app connects as a database user that can **only EXECUTE procedures** (confirmed first that the web server really used that account and could not read a table).
* **Fresh install**: all twenty SQL files, as committed, load into a brand-new database (59 tables, 26 views, 237 procedures and 8 functions, 47 triggers, 9 roles, 76 permissions) and the whole suite passes there too (every check above, 0 failures).
* **Backup and restore**: a backup restored into a new database matched the original on 42 tables, 18 views, 133 procedures, 24 triggers, row counts and money totals; the restored triggers still blocked ledger edits.

Bugs found by this testing and fixed (all in `git log`): the SQL files did not load as written (reserved word, `SIGNAL` and `LIMIT` expressions); the triggers that compute line amounts and totals did not exist (invoices came out as 0.00); no finished-goods batch was ever created (QC and packing had nothing to attach to); dispatch could take stock negative; a Dispatch role right let dispatch staff confirm orders; search with `%%` matched everything; command-line tools printed an HTML page and exited 0 on error.

## Not done, and why

| Item | Why / what it needs |
|---|---|
| **MariaDB not tried** | None was installed. The SQL uses only features in MariaDB 10.6, but run `tools/run_all_tests.ps1` against a MariaDB scratch database before choosing a MariaDB host. |
| Energy and OEE screens | There is no table for meter readings. Needs a table and procedures first. |
| Web Push on phones and other browsers | Tested end to end in Chrome on Windows (the real Google push service accepted the message and the browser showed it). Not tried on Firefox, Safari or a phone. An iPhone only gets push once HPOS is added to the Home Screen. Live production use needs HTTPS (see DEPLOY.md, section 6a). |
| WhatsApp and email | HPOS suggests who to call, what to buy and which orders are at risk, but it does not send or read messages. Orders still arrive by email or WhatsApp and are typed in. Sending needs a provider account and credentials. |
| Automatic buying | The buying advice never creates or sends a purchase order by itself; a person confirms each draft. This is deliberate. |
| Barcode scanning | The factory has no barcodes, so nothing needs a scanner: material is received by item, bags and weight, the mixing check picks lots from a list, and cartons are loaded by ticking them on the delivery note. Carton labels still print a Code 39 barcode with the readable code. The old scan screens (`/scan/dispatch`, `/scan/receive`) still work with a keyboard-style scanner but are out of the menu. Camera scanning would need a JavaScript library and HTTPS. |
| PDF files | Invoices and delivery notes print as HTML and save as PDF from the browser. A server-side PDF needs a library. |
| E-invoice (IRN) and e-way bill generation | The IRN and e-way number are typed in from the GST portal. Calling the portal's API needs credentials and a decision on a provider. |
| Tally GST ledgers | The XML carries the taxable value to a "Sales" ledger. Your Tally ledger names for CGST/SGST/IGST are needed to post tax. |
| Data migration | Customers, suppliers and items import from CSV. Whether to carry over history from the old Node database was never decided; the default is to start fresh. |
| Operator receiving bags | The Operator role may scan but not create stock, so only Store/Admin can receive a bag on a tablet. Confirm this is what the floor wants (one line in `09_seed.sql`). |
| Small gaps | A supplier lead cannot be deleted; an administrator cannot edit another user's name (users edit their own on My profile); sign-in has per-account lock-out but no per-IP throttle (put the host's rate limiting or fail2ban in front). |

## Decisions worth making before go-live

1. Which database host: if MariaDB, run the suite against it first.
2. Who may receive raw material on the floor (Operator or only Store).
3. Whether to import old customers, suppliers and items (CSV) and nothing else.
4. Whether energy/OEE matter enough to design the tables.
5. Tally GST ledger names, if the Tally export is to carry tax.

## Where things are

    database/mysql/          the 20 SQL files, README, upgrades/
    hpos-php/public/         document root (index.php, css, js)
    hpos-php/app/            Core (Router, Db, Auth...), Controllers, Views, routes.php, nav.php
    hpos-php/tools/          setup, cron, backup, restore, import, route audit, dbcall, tests
    docs/                    this file, DEPLOY.md, PERMISSIONS.md, import-samples/
