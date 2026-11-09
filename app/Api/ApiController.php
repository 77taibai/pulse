<?php
declare(strict_types=1);

namespace App\Api;

use App\Models\Report;
use App\Models\Server;
use App\Support\Config;
use App\Support\Normalizer;
use App\Support\Request;
use App\Support\Response;

final class ApiController
{
    public function health(Request $request): never
    {
        Response::json([
            'ok'      => true,
            'service' => (string) Config::get('app.name', 'Pulse'),
            'version' => '1.0.0',
            'time'    => gmdate('c'),
        ]);
    }

    /** POST /api/v1/report —— 服务器状态上报 */
    public function report(Request $request): never
    {
        $server = $this->authenticate($request);

        $raw = $request->rawBody();
        if (strlen($raw) > (int) Config::get('app.max_payload', 262144)) {
            throw new ApiException('上报数据超过大小限制', 413, 'payload_too_large');
        }

        $payload = $this->readPayload($request);
        $data = Normalizer::report($payload, $request->ip());

        // 至少要有内容，全空的上报视为无效。
        // 注意只看上报端提供的原始数值：使用率、剩余空间与负载由服务端计算，不能作为「有内容」的依据。
        $meaningful = false;
        foreach (['cpu_usage', 'mem_total', 'mem_used', 'swap_total', 'swap_used', 'disk_total', 'disk_used', 'net_up', 'net_down'] as $field) {
            if ($data[$field] !== null) {
                $meaningful = true;
                break;
            }
        }
        if (!$meaningful) {
            throw new ApiException('上报内容为空或不包含任何可识别的监控字段', 422, 'empty_report');
        }

        $reportId = Report::store((int) $server['id'], $data);
        // 1/5/15 分钟平均负载由面板依据历史 CPU 使用率算出，入库后立刻回填
        Report::refreshLoad($reportId);
        Server::touch((int) $server['id'], gmdate('Y-m-d H:i:s'), $reportId);

        // 推荐下次上报时间：按该服务器配置的更新频率推算，仅作建议，不做任何强制校验
        $interval = Server::interval($server);
        $nextAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $interval . ' seconds');

        Response::json([
            'ok'         => true,
            'report_id'  => $reportId,
            'server'     => $server['name'],
            'received_at' => gmdate('c'),
            'report_interval' => $interval,
            'next_report_at' => $nextAt->format('c'),
        ], 201);
    }

    /** GET /api/v1/server —— 上报端自检：确认 KEY 有效，并取回推荐上报频率 */
    public function whoami(Request $request): never
    {
        $server = $this->authenticate($request);
        $interval = Server::interval($server);

        Response::json([
            'ok' => true,
            'server' => [
                'id'          => (int) $server['id'],
                'name'        => $server['name'],
                'provider'    => $server['provider'],
                'location'    => $server['location'],
                'last_seen_at' => $server['last_seen_at'],
            ],
            'report_endpoint' => '/api/v1/report',
            // 推荐上报频率（秒）：仅为建议值，上报端可自行决定实际间隔
            'report_interval' => $interval,
            'accepted_fields' => [
                'system'  => ['timezone', 'server_time', 'hostname', 'os', 'kernel', 'arch', 'uptime'],
                'cpu'     => ['model', 'cores', 'usage'],
                'memory'  => ['total', 'used'],
                'swap'    => ['total', 'used'],
                'disk'    => ['total', 'used'],
                'network' => ['up', 'down'],
            ],
            'computed_by_server' => [
                'memory.usage', 'swap.usage', 'disk.usage', 'disk.free', 'load.1', 'load.5', 'load.15',
            ],
        ]);
    }

    private function authenticate(Request $request): array
    {
        $key = $this->extractKey($request);
        if ($key === null || $key === '') {
            throw new ApiException('缺少 API KEY，请使用 Authorization: Bearer <KEY> 或 X-API-Key 头', 401, 'missing_api_key');
        }

        $server = Server::findByApiKey($key);
        if ($server === null) {
            throw new ApiException('API KEY 无效', 401, 'invalid_api_key');
        }

        return $server;
    }

    private function extractKey(Request $request): ?string
    {
        $authorization = $request->header('authorization');
        if ($authorization !== null && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
            return trim($m[1]);
        }

        foreach (['x-api-key', 'x-pulse-key', 'apikey'] as $header) {
            $value = $request->header($header);
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        $queryKey = $request->query('key');
        if (is_string($queryKey) && $queryKey !== '') {
            return $queryKey;
        }

        return null;
    }

    /** 支持 JSON / form-urlencoded / multipart 以及 data=<json> 包装 */
    private function readPayload(Request $request): array
    {
        $contentType = strtolower((string) $request->header('content-type'));
        $isForm = str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');

        if ($isForm) {
            $payload = $_POST;
        } else {
            $payload = $request->json();
            if ($payload === null) {
                throw new ApiException('请求体不是合法的 JSON', 400, 'invalid_json');
            }
        }

        // 兼容 {"data": {...}} 形式
        if (count($payload) === 1 && isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        // 兼容 data=<urlencoded json>
        if (isset($payload['data']) && is_string($payload['data'])) {
            $decoded = json_decode($payload['data'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($payload === []) {
            throw new ApiException('请求体为空', 400, 'empty_body');
        }

        return $payload;
    }
}
