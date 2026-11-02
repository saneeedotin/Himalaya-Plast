# HPOS: Himalaya Plast operating system (PHP + MySQL)

Order-to-cash for a plastic extrusion factory: sales orders, raw-material checks, production and job cards,
QC sheets, buying, dispatch with carton scanning, GST invoices, and the books. It is plain PHP 8 (no Composer,
no framework) in front of a MySQL database that holds every business rule as stored procedures.

```
hpos-php/      the web app (PHP) and its command-line tools
database/mysql/  the database: 20 SQL files, loaded in order
docs/          deploy guide, handover notes, permissions, demo walkthrough
```

## Quick start (about five minutes)

You need PHP 8 and MySQL 8 installed first. The exact list, with how to check each one, is in
**[REQUIREMENTS.md](REQUIREMENTS.md)**.

```bash
git clone https://github.com/saneeedotin/Himalaya-Plast.git
cd Himalaya-Plast/hpos-php

php tools/install.php
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

`install.php` asks for two passwords: your MySQL `root` password (to create the database) and a new password for
the HPOS administrator (at least 10 characters). It then creates the `hpos` database, a database account that can
only run stored procedures, loads the 20 SQL files, writes `hpos-php/config/config.php` and runs a server check.
It stops without changing anything if the database, the config file or the account already exists.

Open <http://127.0.0.1:8080/login> and sign in as `admin@himalayaplast.local` with the password you just chose.

To skip the prompts (scripts, CI), pass them as environment variables:

```bash
HPOS_DB_ADMIN_PASS='mysql-root-password' HPOS_ADMIN_PASSWORD='a-long-hpos-password' php tools/install.php
```

On Windows PowerShell:

```powershell
$env:HPOS_DB_ADMIN_PASS = 'mysql-root-password'; $env:HPOS_ADMIN_PASSWORD = 'a-long-hpos-password'; php tools/install.php
```

Other options (`--host`, `--port`, `--admin-user`, `--db`, `--app-user`, `--admin-email`, `--no-config`) are listed at
the top of [`hpos-php/tools/install.php`](hpos-php/tools/install.php).

### Try it with demo data

```bash
cd hpos-php
php tools/install.php --demo
```

This makes a separate `hpos_demo` database and loads a small demo factory: three customers, suppliers, raw materials,
finished goods, and four orders at different stages (draft, confirmed, in production, dispatched and part-paid).
The demo account has full rights on that database, so use `--demo` for trying things out, never for real data.
[docs/WALKTHROUGH.md](docs/WALKTHROUGH.md) is a 12-minute script that tours every module with it.

A machine has one `config/config.php`, so it points at either the real or the demo database. To switch, delete
`hpos-php/config/config.php` and install again (use `--app-user=<new name>` if the first account name is taken).

## Is it working?

```bash
cd hpos-php
php tools/setup.php
```

Every line should say `OK`. A `WARN` for `mod_rewrite` or `HTTPS` is normal on a laptop. `FAIL` lines say what to fix.

## Something went wrong

| You see | What to do |
|---|---|
| `could not find driver` | Enable `pdo_mysql` in `php.ini` (remove the `;` before `extension=pdo_mysql`), and the same for `mbstring` and `openssl`. |
| `The 'mysql' command-line client was not found` | Add MySQL's `bin` folder to your PATH, or run `php tools/install.php --mysql="C:\path\to\mysql.exe"`. |
| `Cannot connect ... Access denied` | Wrong MySQL admin password or user. Try `mysql -u root -p` by hand first. |
| `Database 'hpos' already has tables` | A previous install is there. Use `--db=hpos2`, or drop the old database yourself if it is disposable. |
| `Account 'hpos_app' already exists` | Another HPOS install on this server uses it. Use `--app-user=hpos_app2`. |
| `config/config.php already exists` | This copy is already installed. Delete the file to reinstall. |
| Server error while loading a SQL file | MySQL refused to create functions or triggers because binary logging is on. Run the installer as `root`, or set `log_bin_trust_function_creators=1`. |
| Page is unstyled or a blank error | Run the server with `-t public` exactly as shown, from inside `hpos-php/`. Set `'debug' => true` in `config/config.php` for a minute to see the message. |

More fixes are in [docs/DEPLOY.md](docs/DEPLOY.md#10-when-something-is-wrong).

## Putting it on a real server

The installer is for laptops and test servers. For a live site follow [docs/DEPLOY.md](docs/DEPLOY.md): HTTPS, two
database accounts (one for loading and backups, one that can only run procedures), `public/` as the web root,
cron for alerts and backups, a restore drill, and the go-live checklist.

## Documentation

| | |
|---|---|
| [REQUIREMENTS.md](REQUIREMENTS.md) | What to install first |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Production deployment, backups, scheduled jobs, push notifications |
| [docs/HANDOVER.md](docs/HANDOVER.md) | What the system does and what is left |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | Who can do what, by role |
| [docs/WALKTHROUGH.md](docs/WALKTHROUGH.md) | Demo script |
| [database/mysql/README.md](database/mysql/README.md) | The SQL files and the rules they enforce |
| [hpos-php/README.md](hpos-php/README.md) | App layout, command-line tools, running the tests |

## Tested on

PHP 8.3 with MySQL 8.0.45 on Windows. MariaDB 10.6+ is expected to work but has not been run.
