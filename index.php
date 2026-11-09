<?php
declare(strict_types=1);

/**
 * Pulse 单一入口。
 *
 * 共享虚拟主机部署：整个目录上传到网站根目录即可，本文件即入口。
 * 首次访问会自动进入安装向导。
 */

use App\Api\ApiException;
use App\Support\Config;
use App\Support\Request;
use App\Support\Response;
use App\Support\Url;
use App\Support\View;

require __DIR__ . '/app/bootstrap.php';

// ---------- 会话 ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => Url::scheme() === 'https',
        'path'     => '/',
    ]);
    session_name('pulse_session');
    session_start();
}

$request = Request::capture();

// ---------- 安全响应头 ----------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ---------- 拦截敏感目录与文件的直接访问 ----------
$reqPath = $request->path();

foreach (['/app/', '/config/', '/database/', '/documentation/'] as $prefix) {
    if (str_starts_with($reqPath, $prefix)) {
        deny_request();
    }
}

$isSensitiveSuffix = (bool) preg_match('/\.(md|sql|log|ini|lock|bak|dist|swp|secret|txt)$/i', $reqPath);
if ($isSensitiveSuffix || in_array($reqPath, ['/README', '/.htaccess', '/.gitignore'], true)) {
    deny_request();
}

View::share('appName', (string) Config::get('app.name', 'Pulse'));

// ---------- 伪静态探测端点 ----------
// 安装向导用浏览器请求这个地址：能拿到 JSON 就说明重写规则生效。
// 放在"未安装"判断之前，保证任何时候都返回 200，不污染控制台。
if ($reqPath === '/__probe') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"ok":true}';
    exit;
}

// ---------- 未安装：就地渲染安装向导 ----------
if (!Config::installed()) {
    if (str_starts_with($reqPath, '/api/')) {
        Response::json([
            'ok'    => false,
            'error' => ['code' => 'not_installed', 'message' => '系统尚未安装完成'],
        ], 503);
    }

    try {
        (new App\Controllers\InstallController())->handle($request);
    } catch (Throwable $e) {
        error_log('[pulse install] ' . $e->getMessage());
        Response::html(
            '<pre style="padding:24px;font:13px/1.6 monospace;background:#111;color:#f88">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>',
            500
        );
    }
    exit;
}

// 已安装却访问安装页，回首页
if (str_starts_with($reqPath, '/install')) {
    Response::redirect(Url::to(''));
}

// ---------- 路由分发 ----------
try {
    // 自动执行表结构升级（补齐新字段、清除历史遗留的 KEY 副本），只需成功执行一次
    App\Support\Schema::ensure();
    App\Support\Maintenance::maybePrune();

    $routes = require __DIR__ . '/config/routes.php';
    [$handler, $params] = resolve($routes, $request);
    $params = array_map('intval', $params);

    [$class, $method] = $handler;
    (new $class())->{$method}($request, ...$params);
} catch (ApiException $e) {
    Response::json([
        'ok'    => false,
        'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
    ] + ($e->details() !== [] ? ['details' => $e->details()] : []), $e->status());
} catch (Throwable $e) {
    error_log('[pulse] ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    if (str_starts_with($reqPath, '/api/')) {
        Response::json([
            'ok'    => false,
            'error' => ['code' => 'internal_error', 'message' => '服务内部错误'],
        ], 500);
    }

    $debug = (bool) Config::get('app.debug', false);
    View::page('errors/500', [
        'title'   => '出错了 · ' . Config::get('app.name', 'Pulse'),
        'message' => $debug ? $e->getMessage() : '服务器内部错误，请稍后再试。',
        'trace'   => $debug ? $e->getTraceAsString() : null,
    ], 500);
}

/**
 * 拒绝访问敏感文件
 */
function deny_request(): never
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

/**
 * 匹配路由，返回 [[类名, 方法名], 参数数组]
 */
function resolve(array $routes, Request $request): array
{
    $key = $request->method() . ' ' . $request->path();

    if (isset($routes[$key])) {
        return [$routes[$key], []];
    }

    foreach ($routes as $route => $handler) {
        [$method, $path] = explode(' ', $route, 2);
        if ($method !== $request->method() || !str_contains($path, '{')) {
            continue;
        }

        $pattern = preg_replace('/\{[a-z_]+\}/i', '__PARAM__', $path);
        $pattern = str_replace('__PARAM__', '(\d+)', preg_quote($pattern, '#'));
        if (preg_match('#^' . $pattern . '$#', $request->path(), $m)) {
            array_shift($m);
            return [$handler, $m];
        }
    }

    if (str_starts_with($request->path(), '/api/')) {
        throw new ApiException('接口不存在', 404, 'not_found');
    }

    View::page('errors/404', ['title' => '页面不存在', 'message' => '你访问的页面不存在。'], 404);
}
