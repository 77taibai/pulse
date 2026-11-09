<?php
declare(strict_types=1);

/**
 * 路由表。键为 "METHOD 路径"，值为 [控制器类, 方法名]。
 * 路径中的 {id} / {report} 为数字占位符。
 */

use App\Api\ApiController;
use App\Controllers\AuthController;
use App\Controllers\DocsController;
use App\Controllers\ServerController;

return [
    // —— Web 面板 ——
    'GET /'                        => [ServerController::class, 'index'],
    'GET /login'                   => [AuthController::class, 'showLogin'],
    'POST /login'                  => [AuthController::class, 'login'],
    'POST /logout'                 => [AuthController::class, 'logout'],

    'GET /servers/create'          => [ServerController::class, 'create'],
    'POST /servers'                => [ServerController::class, 'store'],
    'GET /servers/{id}'            => [ServerController::class, 'show'],
    'GET /servers/{id}/edit'       => [ServerController::class, 'edit'],
    'POST /servers/{id}'           => [ServerController::class, 'update'],
    'POST /servers/{id}/rotate'    => [ServerController::class, 'rotate'],
    'POST /servers/{id}/delete'    => [ServerController::class, 'destroy'],
    'GET /servers/{id}/history'    => [ServerController::class, 'history'],
    'GET /servers/{id}/reports/{report}' => [ServerController::class, 'report'],

    'GET /api-docs'                => [DocsController::class, 'api'],
    'GET /api-docs/download'       => [DocsController::class, 'download'],

    // —— JSON API ——
    'GET /api/v1/health'           => [ApiController::class, 'health'],
    'GET /api/v1/server'           => [ApiController::class, 'whoami'],
    'POST /api/v1/report'          => [ApiController::class, 'report'],
    'GET /api/servers/{id}/series' => [ServerController::class, 'series'],
];
