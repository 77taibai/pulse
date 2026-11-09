<?php
declare(strict_types=1);

namespace App\Support;

/**
 * URL 生成与部署环境探测。
 *
 * 共享虚拟主机的部署形态差异很大：可能装在根目录也可能在 /pulse 这类子目录，
 * 可能支持 mod_rewrite 也可能只支持 PATH_INFO。这里统一处理。
 *
 * 伪静态判定（不发起任何网络请求，避免自请求死锁）：
 *   1. 安装向导会把实测结果写进 config.local.php 的 url_style，优先使用；
 *   2. 未配置时(auto)按本次请求的迹象判断 —— 存在 PATH_INFO 或 URL 含 index.php 即 pathinfo；
 *   3. 无法区分（例如首页）时先按 rewrite 处理。
 * 全程不落盘，因此不需要可写目录。
 */
final class Url
{
    private static ?string $basePath = null;
    private static ?string $style = null;

    /** 应用所在的 URL 前缀，根目录返回 ''，子目录返回 '/pulse' */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        // bootstrap 已把自动探测结果写入配置，这里做兜底
        $configured = Config::get('app.base_path');
        if (is_string($configured)) {
            self::$basePath = rtrim($configured, '/');
        } else {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $dir = str_replace('\\', '/', dirname($script));
            self::$basePath = ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
        }

        return self::$basePath;
    }

    /**
     * URL 风格：
     *   rewrite  -> /pulse/servers/1
     *   pathinfo -> /pulse/index.php/servers/1
     */
    public static function style(): string
    {
        if (self::$style !== null) {
            return self::$style;
        }

        $configured = (string) Config::get('app.url_style', 'auto');
        if ($configured === 'rewrite' || $configured === 'pathinfo') {
            self::$style = $configured;
            return self::$style;
        }

        // —— auto ——
        // 信号 1：PATH_INFO 存在 —— 说明是 /index.php/xxx 这种访问方式
        $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($pathInfo !== '' && $pathInfo !== '/') {
            self::$style = 'pathinfo';
            return self::$style;
        }

        // 信号 2：REDIRECT_URL 存在 —— 说明 mod_rewrite 把请求重写到了入口脚本
        if (isset($_SERVER['REDIRECT_URL'])) {
            self::$style = 'rewrite';
            return self::$style;
        }

        // 信号 3：本次请求 URL 明确带着 index.php
        if (str_contains(self::requestPathWithoutBase(), 'index.php')) {
            self::$style = 'pathinfo';
            return self::$style;
        }

        // 无法区分（例如首页由 DirectoryIndex 直接命中），先按 rewrite 处理
        self::$style = 'rewrite';
        return self::$style;
    }

    /** 去掉 base_path 后的请求路径 */
    private static function requestPathWithoutBase(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        $base = self::basePath();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    /** 生成应用内链接 */
    public static function to(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = self::basePath();

        if ($path === '') {
            return ($base === '' ? '/' : $base . '/');
        }

        // 静态资源不经过入口脚本
        if (str_starts_with($path, 'assets/')) {
            return ($base === '' ? '' : $base) . '/' . $path;
        }

        if (self::style() === 'pathinfo') {
            return ($base === '' ? '' : $base) . '/index.php/' . $path;
        }

        return ($base === '' ? '' : $base) . '/' . $path;
    }

    /** 静态资源（附带文件修改时间做缓存击穿，升级后自动失效） */
    public static function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $url = self::to('assets/' . $file);

        $path = dirname(__DIR__, 2) . '/assets/' . $file;
        if (is_file($path)) {
            $url .= '?v=' . filemtime($path);
        }

        return $url;
    }

    /** 当前站点的完整根地址，例如 https://example.com/pulse */
    public static function origin(): string
    {
        $scheme = self::scheme();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . self::basePath();
    }

    /** 生成应用内链接的完整绝对地址（不重复拼接 base_path） */
    public static function absolute(string $path = ''): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return self::scheme() . '://' . $host . self::to($path);
    }

    public static function scheme(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return 'https';
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return 'https';
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }
        return 'http';
    }

    /** 上报接口的完整地址，用于在面板上展示给用户复制 */
    public static function reportEndpoint(): string
    {
        return self::origin() . self::to('api/v1/report');
    }

    /** 重置探测结果（测试用） */
    public static function reset(): void
    {
        self::$basePath = null;
        self::$style = null;
    }
}
