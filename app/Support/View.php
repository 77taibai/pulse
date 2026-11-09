<?php
declare(strict_types=1);

namespace App\Support;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $data = array_merge(self::$shared, $data);
        $content = self::capture($template, $data);

        if ($layout === '') {
            return $content;
        }

        return self::capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    public static function page(string $template, array $data = [], int $status = 200): never
    {
        $layout = $data['layout'] ?? 'layout';
        unset($data['layout']);
        Response::html(self::render($template, $data, $layout), $status);
    }

    private static function capture(string $template, array $data): string
    {
        $file = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('视图不存在：' . $template);
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

    /** HTML 转义 */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 应用内链接 */
    public static function url(string $path = ''): string
    {
        return Url::to($path);
    }

    /** 静态资源链接 */
    public static function asset(string $file): string
    {
        return Url::asset($file);
    }
}
