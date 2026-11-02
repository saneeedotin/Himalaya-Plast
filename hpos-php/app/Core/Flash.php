<?php
declare(strict_types=1);

namespace App\Core;

/** One-shot messages shown on the next page, kept in the PHP session. */
final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void { self::add('success', $message); }
    public static function error(string $message): void   { self::add('error', $message); }

    /** @return list<array{type:string,message:string}> */
    public static function take(): array
    {
        $all = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $all;
    }
}
