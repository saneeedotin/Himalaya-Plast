<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * Render app/Views/<template>.php inside layout app/Views/layouts/<layout>.php.
     * $layout = null renders the bare template.
     */
    public static function render(string $template, array $data = [], ?string $layout = 'app'): void
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture('layouts/' . $layout, $data + ['content' => $content, 'title' => $data['title'] ?? 'HPOS']);
    }

    private static function capture(string $template, array $data): string
    {
        $file = HPOS_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
