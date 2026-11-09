<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    private array $query;
    private array $post;
    private array $headers;
    private ?array $json = null;

    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string $rawBody
    ) {
        $this->query = $_GET;
        $this->post = $_POST;
        $this->headers = self::collectHeaders();
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        // 去掉子目录前缀（例如 /pulse/servers/1 -> /servers/1）
        $base = Url::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');

        // 去掉入口脚本名（PATH_INFO 形态：/index.php/servers/1）
        // 注意：必须限定脚本名以 .php 结尾。部分环境（如 PHP 内置服务器 + 路由脚本）
        // 会把 SCRIPT_NAME 设成当前请求路径，若不判断后缀会误把 /README.md 剥成 /。
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        if ($script === '' || !str_ends_with(strtolower($script), '.php')) {
            $script = 'index.php';
        }
        if ($path === '/' . $script) {
            $path = '/';
        } elseif (str_starts_with($path, '/' . $script . '/')) {
            $path = substr($path, strlen('/' . $script));
            $path = '/' . trim($path, '/');
        }

        // 查询串兜底：index.php?r=/servers/1
        if ($path === '/' && isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
            $path = '/' . trim($_GET['r'], '/');
        }

        $raw = (string) file_get_contents('php://input');

        return new self($method, $path === '' ? '/' : $path, $raw);
    }

    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function json(): ?array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $raw = trim($this->rawBody);
        if ($raw === '') {
            $this->json = [];
            return $this->json;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $this->json = $decoded;
        return $this->json;
    }

    public function ip(): ?string
    {
        foreach (['x-forwarded-for', 'x-real-ip'] as $header) {
            $value = $this->header($header);
            if ($value !== null && $value !== '') {
                $first = trim(explode(',', $value)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        return $remote !== null ? substr($remote, 0, 45) : null;
    }

    public function isAjax(): bool
    {
        return str_contains((string) $this->header('accept'), 'application/json')
            || strtolower((string) $this->header('x-requested-with')) === 'xmlhttprequest';
    }
}
