<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Auth;
use App\Core\View;

/** Shared helpers for controllers. Controllers read the form, call procedures, render views. */
abstract class Controller
{
    protected function uid(): string
    {
        return (string) Auth::id();
    }

    protected function render(string $template, array $data = []): void
    {
        View::render($template, $data);
    }

    /** Trimmed POST value, '' when missing. */
    protected function str(string $key): string
    {
        return trim((string) ($_POST[$key] ?? ''));
    }

    /** Trimmed POST value, null when empty. */
    protected function nul(string $key): ?string
    {
        $v = $this->str($key);
        return $v === '' ? null : $v;
    }

    /** POST number, null when empty or not numeric. */
    protected function num(string $key): ?string
    {
        $v = $this->str($key);
        return is_numeric($v) ? $v : null;
    }

    /** datetime-local / date input to 'Y-m-d H:i:s', null when empty or invalid. */
    protected function dt(string $key): ?string
    {
        $v = $this->str($key);
        $t = $v === '' ? false : strtotime($v);
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    /** A date from the query string (Y-m-d), or $default when missing or not a real date. */
    protected function dateParam(string $key, string $default): string
    {
        $v = (string) ($_GET[$key] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : $default;
    }

    /** A uuid from the query string, or '' when it is not one. */
    protected function idParam(string $key): string
    {
        $v = (string) ($_GET[$key] ?? '');
        return preg_match('/^[0-9a-f-]{36}$/i', $v) ? $v : '';
    }

    /** The page a small action form came from ("back" field), as a local path only. */
    protected function backTo(string $fallback): string
    {
        $back = $this->str('back');
        return preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-?=&%.]*$#', $back) ? $back : $fallback;
    }

    /** @return list<string> */
    protected function strList(string $key): array
    {
        $v = $_POST[$key] ?? [];
        return is_array($v) ? array_values(array_filter(array_map('strval', $v), 'strlen')) : [];
    }

    /**
     * Run $save (which redirects on success). On a user-facing error, show the form again
     * with the message and the values the user typed.
     */
    protected function saving(callable $save, string $template, array $data): void
    {
        try {
            $save();
        } catch (AppError $e) {
            if ($e->isForbidden()) {
                throw $e;
            }
            http_response_code(422);
            $this->render($template, $data + ['error' => $e->getMessage()]);
        }
    }

    protected function notFound(): never
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Not found', 'code' => 404, 'message' => 'Not found']);
        exit;
    }

    /** Rows of the first result set must contain the record, else 404. */
    protected function must(?array $row): array
    {
        return $row ?? $this->notFound();
    }
}
