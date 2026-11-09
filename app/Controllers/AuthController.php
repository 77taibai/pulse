<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Config;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class AuthController extends Controller
{
    public function showLogin(Request $request): never
    {
        if (!empty($_SESSION['auth'])) {
            Response::redirect(View::url(''));
        }

        View::page('auth/login', [
            'title'  => '登录 · ' . $this->appName(),
            'csrf'   => Response::csrfToken(),
            'flashes' => Response::takeFlashes(),
            'username' => '',
            'layout'   => 'blank',
        ], 200);
    }

    public function login(Request $request): never
    {
        $this->guardCsrf();

        $username = trim((string) $request->post('username', ''));
        $password = (string) $request->post('password', '');

        $expectedUser = (string) Config::get('auth.username', 'admin');
        $expectedHash = (string) Config::get('auth.password_hash', '');

        $userOk = hash_equals($expectedUser, $username);
        $passOk = $expectedHash !== '' && password_verify($password, $expectedHash);

        // 无论哪个环节失败都做一次假校验，避免时序侧信道
        if (!$passOk) {
            password_verify($password, '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin');
        }

        if (!$userOk || !$passOk) {
            Response::flash('error', '用户名或密码不正确');
            Response::redirect(View::url('login'));
        }

        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['auth_user'] = $username;

        Response::redirect(View::url(''));
    }

    public function logout(Request $request): never
    {
        $_SESSION = [];
        session_destroy();
        Response::redirect(View::url('login'));
    }
}
