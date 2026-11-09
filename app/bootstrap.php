<?php
declare(strict_types=1);

use App\Support\Config;

require __DIR__ . '/autoload.php';

Config::load(__DIR__ . '/../config/config.php');

/**
 * 自动识别应用所在的 URL 前缀（根目录为 ''，子目录为 '/pulse'）。
 *
 * 用 SCRIPT_FILENAME 相对 DOCUMENT_ROOT 计算，而不是用 SCRIPT_NAME。
 * 原因是部分环境（例如带路由脚本的服务器）会把 SCRIPT_NAME 设成当前请求路径，
 * 直接用它会推导出错误的目录层级。SCRIPT_FILENAME 始终指向真实入口文件，最可靠。
 */
if (!is_string(Config::get('app.base_path'))) {
    $base = '';

    $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

    if ($scriptFile !== '' && $docRoot !== '') {
        $realScript = realpath($scriptFile);
        $realRoot = realpath($docRoot);

        $sf = str_replace('\\', '/', $realScript !== false ? $realScript : $scriptFile);
        $dr = rtrim(str_replace('\\', '/', $realRoot !== false ? $realRoot : $docRoot), '/');

        if ($dr !== '' && $dr !== '/' && str_starts_with($sf, $dr)) {
            $relativeDir = str_replace('\\', '/', dirname(substr($sf, strlen($dr))));
            $base = in_array($relativeDir, ['.', '/', '\\', ''], true) ? '' : rtrim($relativeDir, '/');
        }
    }

    // 兜底：退回 SCRIPT_NAME（仅在它以 .php 结尾时可信）
    if ($base === '') {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with(strtolower($scriptName), '.php')) {
            $dir = str_replace('\\', '/', dirname($scriptName));
            $base = in_array($dir, ['.', '/', '\\', ''], true) ? '' : rtrim($dir, '/');
        }
    }

    Config::set('app.base_path', $base);
}

$timezone = (string) Config::get('app.timezone', 'UTC');
if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($timezone);
}

mb_internal_encoding('UTF-8');

// 运行期错误不直接输出到响应体，交由异常处理器统一渲染
ini_set('display_errors', '0');
error_reporting(E_ALL);
