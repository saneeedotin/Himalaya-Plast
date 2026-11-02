<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/** Thin PDO wrapper. All business logic is in stored procedures; PHP only calls them. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Config::get('db', []));
        }
        return self::$pdo;
    }

    /** Also used by the server check, which wants the raw PDOException. */
    public static function connect(array $c): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'] ?? '127.0.0.1',
            (int) ($c['port'] ?? 3306),
            $c['name'] ?? 'hpos',
            $c['charset'] ?? 'utf8mb4'
        );
        return new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * CALL a stored procedure and return every result set as a list of row lists.
     *
     * sets[0] is the first SELECT the procedure ran, sets[1] the next, and so on.
     * SQLSTATE 45000 / 45001 (our SIGNALs) become AppError with the DB's message.
     *
     * @param list<scalar|null> $args
     * @return list<list<array<string, mixed>>>
     */
    public static function call(string $proc, array $args = []): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $proc)) {
            throw new \InvalidArgumentException('Bad procedure name');
        }
        $sql = 'CALL ' . $proc . '(' . implode(',', array_fill(0, count($args), '?')) . ')';

        try {
            $st = self::pdo()->prepare($sql);
            $st->execute($args);
            $sets = [];
            do {
                if ($st->columnCount() > 0) {
                    $sets[] = $st->fetchAll();
                }
            } while ($st->nextRowset());
            $st->closeCursor();
            return $sets;
        } catch (PDOException $e) {
            throw self::translate($e);
        }
    }

    /**
     * CALL a procedure whose trailing parameters are OUT (e.g. the new row's id).
     * Returns the OUT values in order; the result sets are discarded.
     *
     * @param list<scalar|null> $in    the IN arguments
     * @return list<mixed>
     */
    public static function callOut(string $proc, array $in, int $outCount = 1): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $proc) || $outCount < 1) {
            throw new \InvalidArgumentException('Bad call');
        }
        $vars = [];
        for ($i = 1; $i <= $outCount; $i++) {
            $vars[] = '@hpos_out' . $i;
        }
        $marks = array_merge(array_fill(0, count($in), '?'), $vars);

        try {
            $st = self::pdo()->prepare('CALL ' . $proc . '(' . implode(',', $marks) . ')');
            $st->execute($in);
            do {
                // drain every result set before reading the OUT variables
            } while ($st->nextRowset());
            $st->closeCursor();
            $row = self::pdo()->query('SELECT ' . implode(',', $vars))->fetch(\PDO::FETCH_NUM);
            return $row === false ? [] : array_values($row);
        } catch (PDOException $e) {
            throw self::translate($e);
        }
    }

    /** First row of the first result set, or null. */
    public static function row(string $proc, array $args = []): ?array
    {
        return self::call($proc, $args)[0][0] ?? null;
    }

    /** All rows of the first result set. */
    public static function rows(string $proc, array $args = []): array
    {
        return self::call($proc, $args)[0] ?? [];
    }

    private static function translate(PDOException $e): \Throwable
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $errno = (int) ($e->errorInfo[1] ?? 0);
        $text = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($state === '45000' || $state === '45001') {
            return new AppError($text, 0, $e);
        }
        // Constraint failures a user can cause with ordinary input get a readable message.
        $key = preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $text, $m) ? $m[1] : null;
        return match ($errno) {
            1062 => new AppError('A record with the same value already exists' . ($key ? " ($key)" : '') . '.', 0, $e),
            1451, 1452 => new AppError('This record is linked to other records and cannot be changed that way.', 0, $e),
            3819 => new AppError('One of the values is not allowed' . (preg_match("/'([^']+)'/", $text, $c) ? " ({$c[1]})" : '') . '.', 0, $e),
            default => $e,
        };
    }
}
