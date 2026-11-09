<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Markdown;
use App\Support\Request;
use App\Support\View;

/**
 * API 文档。
 *
 * 文档以标准 Markdown 文件保存在 documentation/API.md，既可以在这里在线渲染预览，
 * 也可以通过 /api-docs/download 下载原始文件。documentation/ 目录被 Web 服务器与
 * index.php 双重拦截，直接访问该路径不会泄露任何内容。
 */
final class DocsController extends Controller
{
    private const FILE = '/documentation/API.md';

    public function api(Request $request): never
    {
        $markdown = $this->markdown();

        View::page('docs/api', [
            'title'    => 'API 文档 · ' . $this->appName(),
            'html'     => Markdown::render($markdown),
            'download' => View::url('api-docs/download'),
            'flashes'  => [],
        ]);
    }

    /** 以附件形式下载原始 Markdown 文件 */
    public function download(Request $request): never
    {
        $markdown = $this->markdown();

        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="API.md"');
        header('Content-Length: ' . strlen($markdown));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo $markdown;
        exit;
    }

    private function markdown(): string
    {
        $file = dirname(__DIR__, 2) . self::FILE;
        $content = is_file($file) ? @file_get_contents($file) : false;

        return is_string($content) && trim($content) !== ''
            ? $content
            : "# 文档缺失\n\n未找到 `documentation/API.md`，请确认文件已随项目完整上传。";
    }
}
