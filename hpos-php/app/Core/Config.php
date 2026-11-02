<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    /** Loads $HPOS_CONFIG, else config/config.php. A missing file leaves defaults so /setup can still explain the problem. */
    public static function load(): void
    {
        $path = getenv('HPOS_CONFIG') ?: HPOS_ROOT . '/config/config.php';
        self::$values = is_file($path) ? (require $path) : [];
        self::$loaded = is_file($path);
    }

    public static function exists(): bool
    {
        return self::$loaded;
    }

    /** Dot notation: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
