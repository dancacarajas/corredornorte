<?php

declare(strict_types=1);

namespace App\Helpers;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/shell'): void
    {
        $root = dirname(__DIR__, 2);
        $viewFile = $root . '/app/Views/' . $view . '.php';

        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View não encontrada: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
            return;
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = ob_get_clean() ?: '';

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = $root . '/app/Views/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            echo $content;
            return;
        }

        require $layoutFile;
    }

    public static function partial(string $partial, array $data = []): void
    {
        $root = dirname(__DIR__, 2);
        $file = $root . '/app/Views/partials/' . $partial . '.php';
        if (!is_file($file)) {
            return;
        }
        extract($data, EXTR_SKIP);
        require $file;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}
