<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/** Checks the host before anything depends on it. Used by /setup (web) and tools/setup.php (CLI). */
final class ServerCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** @return list<array{label:string,status:string,detail:string}> */
    public static function run(): array
    {
        $r = [];
        $add = static function (string $label, string $status, string $detail) use (&$r): void {
            $r[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };

        $add('PHP version', version_compare(PHP_VERSION, '8.0.0', '>=') ? self::OK : self::FAIL, PHP_VERSION . ' (8.0 or newer needed)');

        foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) {
            $add("Extension $ext", extension_loaded($ext) ? self::OK : self::FAIL, extension_loaded($ext) ? 'loaded' : 'missing');
        }

        $argon = defined('PASSWORD_ARGON2ID');
        $add('Password hashing', self::OK, $argon
            ? 'argon2id available (matches existing hashes)'
            : 'argon2id missing, new passwords will use bcrypt. Existing argon2id hashes cannot be verified here.');
        if (!$argon) {
            $r[array_key_last($r)]['status'] = self::WARN;
        }

        $savePath = session_save_path() ?: sys_get_temp_dir();
        $add('Session folder writable', is_writable($savePath) ? self::OK : self::FAIL, $savePath);

        $add('Config file', Config::exists() ? self::OK : self::FAIL,
            Config::exists() ? 'found' : 'config/config.php missing (copy config.example.php)');

        if (function_exists('apache_get_modules')) {
            $has = in_array('mod_rewrite', apache_get_modules(), true);
            $add('mod_rewrite', $has ? self::OK : self::WARN, $has ? 'enabled' : 'not enabled; pretty URLs will not work without it');
        } else {
            $add('mod_rewrite', self::WARN, 'cannot be detected from this server API (' . PHP_SAPI . ')');
        }

        $add('HTTPS', is_https() ? self::OK : self::WARN, is_https() ? 'yes' : 'no, the session cookie will not carry the Secure flag');

        self::checkDatabase($add);
        return $r;
    }

    /** @param callable(string,string,string):void $add */
    private static function checkDatabase(callable $add): void
    {
        if (!Config::exists()) {
            return;
        }
        try {
            $pdo = Db::connect(Config::get('db', []));
        } catch (Throwable $e) {
            $add('Database connection', self::FAIL, $e->getMessage());
            return;
        }
        $add('Database connection', self::OK, 'connected to ' . Config::get('db.name'));

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $num = preg_match('/(\d+\.\d+\.\d+)/', $version, $m) ? $m[1] : '0.0.0';
        $ok = $isMaria ? version_compare($num, '10.6.0', '>=') : version_compare($num, '8.0.16', '>=');
        $add('Database version', $ok ? self::OK : self::FAIL, $version . ($isMaria ? ' (MariaDB 10.6+ needed)' : ' (MySQL 8.0.16+ needed)'));

        try {
            $pdo->query("SELECT 1 FROM JSON_TABLE('[1]', '\$[*]' COLUMNS (a INT PATH '\$')) t")->fetchAll();
            $add('JSON_TABLE', self::OK, 'works');
        } catch (Throwable $e) {
            $add('JSON_TABLE', self::FAIL, 'not supported: ' . $e->getMessage());
        }

        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name LIKE 'sp\\_%'")->fetchColumn();
            $add('Stored procedures', $n > 50 ? self::OK : self::FAIL, "$n visible to this user (run database/mysql/*.sql if 0)");
        } catch (Throwable $e) {
            $add('Stored procedures', self::FAIL, $e->getMessage());
        }

        try {
            $pdo->query("SELECT fn_can('none', 'none', 'none')")->fetchAll();
            $add('EXECUTE privilege', self::OK, 'functions callable');
        } catch (Throwable $e) {
            $add('EXECUTE privilege', self::FAIL, $e->getMessage());
        }

        try {
            $ev = (string) $pdo->query('SELECT @@event_scheduler')->fetchColumn();
            $add('MySQL events', self::OK, "event_scheduler = $ev. " . (strtoupper($ev) === 'ON' ? '' : 'Use tools/cron.php for alerts and session purge.'));
        } catch (Throwable) {
            $add('MySQL events', self::WARN, 'unknown; use tools/cron.php');
        }
    }

    public static function failed(array $results): bool
    {
        foreach ($results as $r) {
            if ($r['status'] === self::FAIL) {
                return true;
            }
        }
        return false;
    }
}
