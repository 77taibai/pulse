<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Config;
use App\Support\Response;
use App\Support\View;

abstract class Controller
{
    protected function requireAuth(): void
    {
        if (empty($_SESSION['auth'])) {
            Response::flash('error', '请先登录');
            Response::redirect(View::url('login'));
        }
    }

    protected function guardCsrf(): void
    {
        $token = $_POST['_token'] ?? null;
        if (!Response::verifyCsrf(is_string($token) ? $token : null)) {
            Response::flash('error', '会话已过期，请重试');
            $referer = $_SERVER['HTTP_REFERER'] ?? View::url('');
            Response::redirect($referer);
        }
    }

    protected function back(): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? View::url('');
        Response::redirect($referer);
    }

    protected function notFound(string $message = '页面不存在'): never
    {
        View::page('errors/404', ['message' => $message], 404);
    }

    protected function appName(): string
    {
        return (string) Config::get('app.name', 'Pulse');
    }
}
