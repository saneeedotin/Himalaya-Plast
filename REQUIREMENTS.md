# Requirements

What has to be installed before `php tools/install.php` will work. Nothing else is needed: there is no Composer,
npm or build step.

## To run it on a laptop or test machine

| Need | Minimum | Check |
|---|---|---|
| PHP | 8.0 or newer (tested on 8.3) | `php -v` |
| PHP extensions | `pdo_mysql`, `mbstring`, `json`, `openssl` | `php -m` |
| MySQL | 8.0.16 or newer (tested on 8.0.45) | `mysql --version` |
| MySQL command-line client | the `mysql` program, on PATH | `mysql --version` |
| A MySQL account that can create databases and users | usually `root` | `mysql -u root -p` |
| A modern browser | Chrome, Edge, Firefox or Safari | |
| Free port | 3306 (MySQL) and 8080 (the dev server) | |

MariaDB 10.6 or newer should also work (HPOS uses `JSON_TABLE`, check constraints and `DEFAULT (UUID())`), but it
has not been tested yet.

### Installing the pieces

**Windows**
- PHP: download the *Thread Safe* x64 zip from <https://windows.php.net/download>, unzip to `C:\php`, add
  `C:\php` to PATH, copy `php.ini-development` to `php.ini`, and uncomment `extension=pdo_mysql`,
  `extension=mbstring` and `extension=openssl`.
- MySQL: install *MySQL Community Server* from <https://dev.mysql.com/downloads/mysql/> and keep the
  command-line client; add `C:\Program Files\MySQL\MySQL Server 8.0\bin` to PATH.

**macOS** (Homebrew): `brew install php mysql` then `brew services start mysql`.

**Ubuntu / Debian**: `sudo apt install php-cli php-mysql php-mbstring mysql-server mysql-client`.

Open a new terminal after changing PATH, then run the checks in the table above.

## Extra, only if you use them

| Need | For | Check |
|---|---|---|
| PowerShell 7 (`pwsh`) | running the automated test suite, `tools/run_all_tests.ps1` | `pwsh -v` |
| HTTPS | a live server: the session cookie's Secure flag and Web Push (browsers allow push on `localhost` without it) | |
| Apache with `mod_rewrite` (or Nginx equivalent) | a live server; the dev server (`php -S`) does not need it | `php tools/setup.php` |
| Cron | alerts every 10 minutes and nightly backups | |
| `mysqldump` | backups (`tools/backup.php`) | `mysqldump --version` |
| Outgoing HTTPS from the server | Web Push to browsers | `php tools/push_selftest.php` |

The full production checklist is [docs/DEPLOY.md](docs/DEPLOY.md).

## How to confirm everything is in place

```bash
php -v
php -m
mysql --version
```

`php -m` must list `pdo_mysql`, `mbstring`, `json` and `openssl`. If something is missing, `php tools/install.php`
tells you which one before it changes anything.
