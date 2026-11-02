# hpos-php

Plain PHP 8 front end for the HPOS database (`../database/mysql`). No Composer, no framework.
Controllers check the CSRF token, pass the signed-in user's id to a stored procedure, and render the
result sets. The database holds the business rules and enforces permissions.

Install it: [../docs/DEPLOY.md](../docs/DEPLOY.md). What it does and what is left: [../docs/HANDOVER.md](../docs/HANDOVER.md).
Who can do what: [../docs/PERMISSIONS.md](../docs/PERMISSIONS.md).

## Run locally

    cp config/config.example.php config/config.php      # then fill in the db section
    php tools/setup.php                                  # server check
    php -S 127.0.0.1:8080 -t public tools/dev-router.php

Sign in at http://127.0.0.1:8080/login.

## Layout

    public/index.php     entry point (routes are in app/routes.php)
    app/Core/            Router, Db, Auth, Csrf, Flash, View, Csv, Barcode, ServerCheck
    app/Controllers/     one per module
    app/Views/           layouts + one folder per module
    app/nav.php          sidebar menu (filtered by permission)
    tools/               setup, cron, backup, restore, import_csv, audit_routes, dbcall, tests

## Tools

| Command | What it does |
|---|---|
| `php tools/setup.php` | checks PHP, extensions, database version, JSON_TABLE, EXECUTE rights |
| `php tools/cron.php` | raises alerts and clears old sessions (schedule every 10 minutes) |
| `php tools/backup.php --dir=... --keep=30` | compressed dump of tables, views, procedures, triggers |
| `php tools/restore.php file.sql.gz --database=new_db` | restores into an empty database; never over the live one |
| `php tools/import_csv.php customers file.csv [--commit]` | loads customers, suppliers or items; dry run by default |
| `php tools/audit_routes.php` | lists every route and its permission; fails on an unprotected one |
| `php tools/permissions_report.php` | Markdown matrix of roles and rights |
| `php tools/hash_password.php` | prints a password hash for the first administrator |

## Tests (use a database whose name ends in `_scratch` or `_test`)

    pwsh tools/run_all_tests.ps1
    pwsh tools/run_all_tests.ps1 -ConfigPath C:\path\app-user-config.php    # run as the EXECUTE-only account

That runs PHP syntax, the route audit, the server check, the stored-procedure scenario test and the seven
HTTP smoke tests (`tools/smoke_phase1.ps1` to `smoke_phase7.ps1`, 457 checks). The smoke tests sign in as the
administrator from `config/dev-admin.txt` (line 1 email, line 2 password; git-ignored) and need PHP on PATH and PowerShell 7.

## Phases

0 foundation, 1 admin and masters, 2 sales cockpit, 3 shop floor, 4 buying/quality/dispatch, 5 accounts,
6 dashboard and extras, 7 hardening and handover: all built and tested.
