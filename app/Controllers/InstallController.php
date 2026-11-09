<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Config;
use App\Support\Db;
use App\Support\Request;
use App\Support\Response;
use App\Support\Url;
use App\Support\View;

/**
 * Web 安装向导。全部初始化步骤都在浏览器内完成，不依赖命令行。
 */
final class InstallController extends Controller
{
    public function handle(Request $request): void
    {
        $configFile = dirname(__DIR__, 2) . '/config/config.local.php';

        if ($request->isPost()) {
            $this->install($request, $configFile);
            return;
        }

        View::page('install/index', [
            'title'       => '安装向导 · Pulse',
            'layout'      => 'blank',
            'checks'      => $this->checks($configFile),
            'csrf'        => Response::csrfToken(),
            'flashes'     => Response::takeFlashes(),
            'old'         => [],
            'basePath'    => Url::basePath() === '' ? '/' : Url::basePath(),
            'origin'      => Url::origin(),
            // 表单提交到"当前实际地址"，保证伪静态不可用时也能正常提交
            'formAction'  => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            // 浏览器用它实测伪静态：rewrite 生效时这个地址可直接命中
            'probeUrl'    => Url::origin() . Url::to('__probe'),
        ]);
    }

    /** 环境自检 */
    private function checks(string $configFile): array
    {
        $configDir = dirname($configFile);

        $items = [
            [
                'label'  => 'PHP 版本 ≥ 8.0',
                'value'  => PHP_VERSION,
                'ok'     => PHP_VERSION_ID >= 80000,
                'hint'   => '需要 PHP 8.0 或更高版本',
            ],
            [
                'label'  => 'PDO MySQL 扩展',
                'value'  => extension_loaded('pdo_mysql') ? '已启用' : '未启用',
                'ok'     => extension_loaded('pdo_mysql'),
                'hint'   => '请在主机面板开启 pdo_mysql',
            ],
            [
                'label'  => 'mbstring 扩展',
                'value'  => extension_loaded('mbstring') ? '已启用' : '未启用',
                'ok'     => extension_loaded('mbstring'),
                'hint'   => '用于正确处理中文字符',
            ],
            [
                'label'  => 'json 扩展',
                'value'  => extension_loaded('json') ? '已启用' : '未启用',
                'ok'     => extension_loaded('json'),
                'hint'   => 'API 必需',
            ],
            [
                'label'  => 'config 目录可写',
                'value'  => is_writable($configDir) ? '可写' : '不可写',
                'ok'     => is_writable($configDir),
                'hint'   => '安装向导需要写入 config/config.local.php',
            ],
            [
                'label'  => '伪静态 (mod_rewrite)',
                'value'  => $this->rewriteLooksAvailable() ? '可用' : '未知',
                'ok'     => true, // 非阻塞项，安装时会自动实测
                'hint'   => '不可用时自动切换为 index.php/xxx 形式',
            ],
        ];

        return $items;
    }

    private function rewriteLooksAvailable(): bool
    {
        if (function_exists('apache_get_modules')) {
            return in_array('mod_rewrite', apache_get_modules(), true);
        }
        // 无法直接判断时按可用处理，安装时再实测
        return true;
    }

    private function install(Request $request, string $configFile): void
    {
        if (!Response::verifyCsrf(is_string($request->post('_token')) ? $request->post('_token') : null)) {
            Response::flash('error', '会话已过期，请重新提交');
            Response::redirect(Url::to('install'));
        }
        $dbHost = trim((string) $request->post('db_host', ''));
        $dbPort = (int) $request->post('db_port', 3306);
        $dbName = trim((string) $request->post('db_name', ''));
        $dbUser = trim((string) $request->post('db_user', ''));
        $dbPass = (string) $request->post('db_pass', '');
        $adminUser = trim((string) $request->post('admin_user', ''));
        $adminPass = (string) $request->post('admin_pass', '');
        $adminPass2 = (string) $request->post('admin_pass2', '');
        $timezone = trim((string) $request->post('timezone', 'Asia/Shanghai'));

        $errors = [];
        if ($dbHost === '') $errors[] = '数据库主机不能为空';
        if ($dbName === '') $errors[] = '数据库名不能为空';
        if ($dbUser === '') $errors[] = '数据库用户名不能为空';
        if ($adminUser === '') $errors[] = '管理员用户名不能为空';
        if (strlen($adminPass) < 6) $errors[] = '管理员密码至少 6 位';
        if ($adminPass !== $adminPass2) $errors[] = '两次输入的管理员密码不一致';
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = '时区无效';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Response::flash('error', $error);
            }
            Response::redirect(Url::to('install'));
        }

        // 连接数据库
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort ?: 3306);
            $pdo = new \PDO($dsn, $dbUser, $dbPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 8,
            ]);
        } catch (\PDOException $e) {
            Response::flash('error', '数据库连接失败：' . $e->getMessage());
            Response::redirect(Url::to('install'));
        }

        // 数据库不存在则尝试创建
        try {
            $pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');
        } catch (\PDOException $e) {
            try {
                $pdo->exec(
                    'CREATE DATABASE `' . str_replace('`', '', $dbName)
                    . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
                $pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');
            } catch (\PDOException $e2) {
                Response::flash('error', '数据库不存在且无法自动创建，请先在主机面板手动创建：' . $dbName);
                Response::redirect(Url::to('install'));
            }
        }

        // 建表
        try {
            $this->createTables($pdo);
        } catch (\Throwable $e) {
            Response::flash('error', '创建数据表失败：' . $e->getMessage());
            Response::redirect(Url::to('install'));
        }

        $config = [
            'app' => [
                'base_path' => Url::basePath(),
                // 浏览器实测结果：能直接命中 /api/v1/health 说明伪静态可用
                'url_style' => ($request->post('rewrite_ok') === '1') ? 'rewrite' : 'pathinfo',
                'timezone'  => $timezone !== '' ? $timezone : 'Asia/Shanghai',
            ],
            'db' => [
                'host' => $dbHost,
                'port' => $dbPort ?: 3306,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ],
            'auth' => [
                'username' => $adminUser,
                'password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
            ],
        ];

        if (!$this->writeConfig($configFile, $config)) {
            Response::flash('error', '无法写入 config/config.local.php，请检查目录权限');
            Response::redirect(Url::to('install'));
        }

        // 清空会话，强制用新账号重新登录
        $_SESSION = [];
        session_regenerate_id(true);

        Response::flash('success', '安装完成，请使用刚才设置的管理员账号登录');
        Response::redirect(Url::to('login'));
    }

    private function writeConfig(string $configFile, array $config): bool
    {
        $php = "<?php\n"
            . "// 由安装向导自动生成，包含敏感信息，请勿公开\n"
            . "// 生成时间：" . date('Y-m-d H:i:s') . "\n"
            . 'return ' . var_export($config, true) . ";\n";

        $ok = @file_put_contents($configFile, $php, LOCK_EX) !== false;
        if ($ok) {
            @chmod($configFile, 0640);
        }
        return $ok;
    }

    private function createTables(\PDO $pdo): void
    {
        /** @var array<string, string> $tables */
        $tables = require dirname(__DIR__, 2) . '/database/schema.php';

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }
    }
}
