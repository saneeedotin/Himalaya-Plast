<?php
declare(strict_types=1);

namespace App\Core;

/** An error whose message is safe to show to the user (raised by SIGNAL in the DB, or by validation). */
final class AppError extends \RuntimeException
{
    public function isForbidden(): bool
    {
        return str_starts_with($this->getMessage(), 'Forbidden');
    }
}
