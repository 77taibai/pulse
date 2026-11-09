<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Report;
use App\Models\Server;
use App\Support\Config;
use App\Support\Format;
use App\Support\KeyReveal;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class ServerController extends Controller
{
    public function index(Request $request): never
    {
        $this->requireAuth();

        $servers = Server::overview();
        $offlineAfter = (int) Config::get('app.offline_after', 180);

        $stats = [
            'total'   => count($servers),
            'online'  => count(array_filter($servers, static fn (array $s): bool => $s['online'])),
            'offline' => count(array_filter($servers, static fn (array $s): bool => !$s['online'])),
        ];

        View::page('servers/index', [
            'title'        => '服务器总览 · ' . $this->appName(),
            'servers'      => $servers,
            'stats'        => $stats,
            'offlineAfter' => $offlineAfter,
            'csrf'         => Response::csrfToken(),
            'flashes'      => Response::takeFlashes(),
        ]);
    }

    public function create(Request $request): never
    {
        $this->requireAuth();

        View::page('servers/create', [
            'title'   => '添加服务器 · ' . $this->appName(),
            'csrf'    => Response::csrfToken(),
            'flashes' => Response::takeFlashes(),
            'old'     => [],
            'interval' => Server::INTERVAL_DEFAULT,
            'intervals' => $this->intervalOptions(),
        ]);
    }

    public function store(Request $request): never
    {
        $this->requireAuth();
        $this->guardCsrf();

        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            Response::flash('error', '服务器名称不能为空');
            Response::redirect(View::url('servers/create'));
        }

        $provider = trim((string) $request->post('provider', ''));
        $location = trim((string) $request->post('location', ''));
        $note = trim((string) $request->post('note', ''));
        $interval = Server::normalizeInterval($request->post('report_interval', Server::INTERVAL_DEFAULT));

        [$id, $apiKey] = Server::create($name, $provider, $location, $note, $interval);

        // 明文只在接下来这一次详情页渲染中出现，刷新即消失
        KeyReveal::put($id, $apiKey);

        Response::flash('success', '服务器已创建，API KEY 只显示这一次，请立即复制保存');
        Response::redirect(View::url('servers/' . $id));
    }

    public function show(Request $request, int $id): never
    {
        $this->requireAuth();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        $latest = Report::latest($id);
        $series = Report::series($id, 240);
        $total = Report::count($id);
        $offlineAfter = (int) Config::get('app.offline_after', 180);
        $online = false;
        if (!empty($server['last_seen_at'])) {
            $seen = strtotime($server['last_seen_at'] . ' UTC');
            $online = $seen !== false && (time() - $seen) <= $offlineAfter;
        }

        $createdKey = KeyReveal::pull($id);

        // 页面可能携带一次性明文 KEY，禁止浏览器缓存，避免后退时被翻出来
        Response::noStore();

        View::page('servers/show', [
            'title'      => $server['name'] . ' · ' . $this->appName(),
            'server'     => $server,
            'latest'     => $latest,
            'chartData'  => $this->chartPayload($series),
            'reportTotal' => $total,
            'online'     => $online,
            'interval'   => Server::interval($server),
            'csrf'       => Response::csrfToken(),
            'flashes'    => Response::takeFlashes(),
            // 仅创建 / 重置后的第一次渲染有值，其余情况一律为 null
            'createdKey' => $createdKey,
        ]);
    }

    /** 折线图数据接口（面板 AJAX 轮询） */
    public function series(Request $request, int $id): never
    {
        $this->requireAuth();

        if (Server::find($id) === null) {
            Response::json(['ok' => false, 'error' => '服务器不存在'], 404);
        }

        $limit = (int) $request->query('limit', 240);
        $rows = Report::series($id, $limit);

        Response::json([
            'ok'    => true,
            'data'  => $this->chartPayload($rows),
            'count' => Report::count($id),
        ]);
    }

    public function edit(Request $request, int $id): never
    {
        $this->requireAuth();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        View::page('servers/edit', [
            'title'    => '编辑 ' . $server['name'] . ' · ' . $this->appName(),
            'server'   => $server,
            'interval' => Server::interval($server),
            'intervals' => $this->intervalOptions(),
            'csrf'     => Response::csrfToken(),
            'flashes'  => Response::takeFlashes(),
        ]);
    }

    public function update(Request $request, int $id): never
    {
        $this->requireAuth();
        $this->guardCsrf();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            Response::flash('error', '服务器名称不能为空');
            Response::redirect(View::url('servers/' . $id . '/edit'));
        }

        Server::update(
            $id,
            $name,
            trim((string) $request->post('provider', '')),
            trim((string) $request->post('location', '')),
            trim((string) $request->post('note', '')),
            Server::normalizeInterval($request->post('report_interval', Server::INTERVAL_DEFAULT))
        );

        Response::flash('success', '服务器信息已更新');
        Response::redirect(View::url('servers/' . $id));
    }

    public function rotate(Request $request, int $id): never
    {
        $this->requireAuth();
        $this->guardCsrf();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        $newKey = Server::rotateKey($id);
        KeyReveal::put($id, $newKey);

        Response::flash('success', 'API KEY 已重新生成，旧 KEY 立即失效；新 KEY 只显示这一次，请立即复制保存');
        Response::redirect(View::url('servers/' . $id));
    }

    public function destroy(Request $request, int $id): never
    {
        $this->requireAuth();
        $this->guardCsrf();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        Server::delete($id);
        Response::flash('success', '服务器「' . $server['name'] . '」及其历史数据已删除');
        Response::redirect(View::url(''));
    }

    public function history(Request $request, int $id): never
    {
        $this->requireAuth();

        $server = Server::find($id);
        if ($server === null) {
            $this->notFound('服务器不存在');
        }

        $page = (int) $request->query('page', 1);
        $history = Report::history($id, $page);

        View::page('servers/history', [
            'title'   => $server['name'] . ' · 上报记录 · ' . $this->appName(),
            'server'  => $server,
            'history' => $history,
            'csrf'    => Response::csrfToken(),
            'flashes' => Response::takeFlashes(),
        ]);
    }

    public function report(Request $request, int $serverId, int $reportId): never
    {
        $this->requireAuth();

        $server = Server::find($serverId);
        $report = Report::find($reportId);
        if ($server === null || $report === null || (int) $report['server_id'] !== $serverId) {
            $this->notFound('记录不存在');
        }

        View::page('servers/report', [
            'title'  => '上报 #' . $reportId . ' · ' . $this->appName(),
            'server' => $server,
            'report' => $report,
            'csrf'   => Response::csrfToken(),
            'flashes' => Response::takeFlashes(),
        ]);
    }

    /**
     * 更新频率的可选值（秒）。这些只是推荐值，上报端不受任何强制约束。
     *
     * @return array<int, string>
     */
    private function intervalOptions(): array
    {
        return [
            10  => '10 秒',
            30  => '30 秒',
            60  => '1 分钟',
            120 => '2 分钟',
            300 => '5 分钟',
            600 => '10 分钟',
            1800 => '30 分钟',
            3600 => '1 小时',
        ];
    }

    /**
     * 组装折线图数据。
     *
     * 横轴优先用服务器自报的测量时间 reported_at，缺失时退回 received_at，
     * 保证同一分钟内多次上报也能在图上区分先后。
     */
    private function chartPayload(array $rows): array
    {
        $labels = [];
        $fullLabels = [];
        $cpu = [];
        $mem = [];
        $swap = [];
        $disk = [];
        $up = [];
        $down = [];

        foreach ($rows as $row) {
            $time = $row['reported_at'] ?? null;
            if ($time === null || $time === '') {
                $time = $row['received_at'];
            }

            $labels[] = Format::datetime($time, 'H:i:s');
            $fullLabels[] = Format::datetime($time, 'm-d H:i:s');
            $cpu[] = $row['cpu_usage'] !== null ? (float) $row['cpu_usage'] : null;
            $mem[] = $row['mem_usage'] !== null ? (float) $row['mem_usage'] : null;
            $swap[] = $row['swap_usage'] !== null ? (float) $row['swap_usage'] : null;
            $disk[] = $row['disk_usage'] !== null ? (float) $row['disk_usage'] : null;
            $up[] = $row['net_up'] !== null ? (int) $row['net_up'] : null;
            $down[] = $row['net_down'] !== null ? (int) $row['net_down'] : null;
        }

        return [
            'labels'     => $labels,
            'fullLabels' => $fullLabels,
            'cpu'        => $cpu,
            'mem'        => $mem,
            'swap'       => $swap,
            'disk'       => $disk,
            'up'         => $up,
            'down'       => $down,
        ];
    }
}
