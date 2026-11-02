<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Login, cookie session and permissions.
 * The cookie holds a random token; the database stores only its SHA-256.
 */
final class Auth
{
    private const COOKIE = 'hpos_session';

    private static bool $resolved = false;
    private static ?array $user = null;
    /** @var array<string,true>|null  keys are "resource:action" */
    private static ?array $perms = null;

    public static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function token(): ?string
    {
        $t = $_COOKIE[self::COOKIE] ?? null;
        return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
    }

    public static function tokenHash(): ?string
    {
        $t = self::token();
        return $t === null ? null : hash('sha256', $t);
    }

    /** Current user row, or null when not signed in. Looked up once per request. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $hash = self::tokenHash();
        if ($hash === null) {
            return null;
        }
        $hours = (int) Config::get('session_hours', 8);
        self::$user = Db::row('sp_auth_get_session', [$hash, $hours]);
        if (self::$user === null) {
            self::clearCookie();
        } else {
            self::setCookie((string) self::token(), $hours);
        }
        return self::$user;
    }

    public static function id(): ?string
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $_SESSION['next'] = self::requestPath();
            }
            redirect('/login');
        }
    }

    /** Same wildcard rule as fn_can() in the database. The DB still enforces access either way. */
    public static function can(string $resource, string $action = 'read'): bool
    {
        $id = self::id();
        if ($id === null) {
            return false;
        }
        if (self::$perms === null) {
            self::$perms = [];
            foreach (Db::rows('sp_auth_get_permissions', [$id]) as $p) {
                self::$perms[$p['resource'] . ':' . $p['action']] = true;
            }
        }
        $p = self::$perms;
        return isset($p["*:*"]) || isset($p["*:$action"]) || isset($p["$resource:*"]) || isset($p["$resource:$action"]);
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $row = Db::row('sp_auth_get_user_by_email', [$email]);

        // Always run one password check so unknown emails cost the same time as known ones.
        $valid = password_verify($password, $row['password_hash'] ?? self::dummyHash());

        if ($row === null) {
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        if ((int) $row['is_locked'] === 1) {
            return ['ok' => false, 'error' => 'Too many failed attempts. This account is locked for 15 minutes.'];
        }
        if (!$valid) {
            Db::call('sp_auth_login_failed', [$row['id'], client_ip()]);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        if ((int) $row['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'This account is deactivated. Contact your administrator.'];
        }

        $hours = (int) Config::get('session_hours', 8);
        $token = bin2hex(random_bytes(32));
        Db::call('sp_auth_login_success', [$row['id'], hash('sha256', $token), client_ip(), client_agent(), $hours]);
        self::setCookie($token, $hours);
        $_COOKIE[self::COOKIE] = $token;
        self::$resolved = false;
        self::$perms = null;
        return ['ok' => true];
    }

    public static function logout(): void
    {
        $hash = self::tokenHash();
        if ($hash !== null) {
            Db::call('sp_auth_logout', [$hash]);
        }
        self::clearCookie();
        unset($_COOKIE[self::COOKIE]);
        self::$resolved = true;
        self::$user = null;
        self::$perms = null;
    }

    public static function takeNext(): string
    {
        $next = $_SESSION['next'] ?? '/';
        unset($_SESSION['next']);
        // local paths only
        return is_string($next) && preg_match('#^/(?![/\\\\])#', $next) ? $next : '/';
    }

    private static function requestPath(): string
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = base_path();
        $path = ($base !== '' && str_starts_with($uri, $base)) ? substr($uri, strlen($base)) : $uri;
        return $path === '' ? '/' : $path;
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash('dummy-password-for-timing');
    }

    private static function setCookie(string $token, int $hours): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $hours * 3600,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
