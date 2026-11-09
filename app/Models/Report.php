<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Db;

final class Report
{
    private const FIELDS = [
        'server_id', 'received_at', 'reported_at', 'timezone', 'server_time_raw', 'clock_skew',
        'uptime', 'hostname', 'os', 'kernel', 'arch',
        'cpu_model', 'cpu_cores', 'cpu_usage',
        'mem_total', 'mem_used', 'mem_usage',
        'swap_total', 'swap_used', 'swap_usage',
        'disk_total', 'disk_used', 'disk_free', 'disk_usage',
        'net_up', 'net_down',
        'load1', 'load5', 'load15',
        'ip', 'raw_json',
    ];

    public static function store(int $serverId, array $data, array $rawPayload): int
    {
        $row = ['server_id' => $serverId, 'received_at' => gmdate('Y-m-d H:i:s')];
        foreach (self::FIELDS as $field) {
            if ($field === 'server_id' || $field === 'received_at') {
                continue;
            }
            $row[$field] = $data[$field] ?? null;
        }
        $row['raw_json'] = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $columns = array_keys($row);
        $sql = 'INSERT INTO reports (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return Db::insert($sql, array_values($row));
    }

    /**
     * 由面板计算 1/5/15 分钟平均负载。
     *
     * 面板拿不到服务器的 run-queue 长度，因此这里用「最近 N 分钟内上报的 CPU 使用率均值」
     * 作为负载指标，口径统一由服务端决定，不依赖上报脚本自己算。
     * 窗口以服务端接收时间 received_at 为准，避免上报端时钟偏差影响统计。
     *
     * @return array{load1: float|null, load5: float|null, load15: float|null}
     */
    public static function refreshLoad(int $reportId): array
    {
        $report = Db::one('SELECT server_id, received_at FROM reports WHERE id = ?', [$reportId]);
        if ($report === null) {
            return ['load1' => null, 'load5' => null, 'load15' => null];
        }

        $anchor = (int) (new \DateTimeImmutable((string) $report['received_at'], new \DateTimeZone('UTC')))->getTimestamp();

        $row = Db::one(
            'SELECT
                AVG(CASE WHEN received_at >= ? THEN cpu_usage END) AS load1,
                AVG(CASE WHEN received_at >= ? THEN cpu_usage END) AS load5,
                AVG(CASE WHEN received_at >= ? THEN cpu_usage END) AS load15
             FROM reports
             WHERE server_id = ? AND received_at >= ? AND received_at <= ?',
            [
                gmdate('Y-m-d H:i:s', $anchor - 60),
                gmdate('Y-m-d H:i:s', $anchor - 300),
                gmdate('Y-m-d H:i:s', $anchor - 900),
                (int) $report['server_id'],
                gmdate('Y-m-d H:i:s', $anchor - 900),
                gmdate('Y-m-d H:i:s', $anchor),
            ]
        );

        $loads = [
            'load1'  => $row !== null && $row['load1'] !== null ? round((float) $row['load1'], 2) : null,
            'load5'  => $row !== null && $row['load5'] !== null ? round((float) $row['load5'], 2) : null,
            'load15' => $row !== null && $row['load15'] !== null ? round((float) $row['load15'], 2) : null,
        ];

        Db::exec(
            'UPDATE reports SET load1 = ?, load5 = ?, load15 = ? WHERE id = ?',
            [$loads['load1'], $loads['load5'], $loads['load15'], $reportId]
        );

        return $loads;
    }

    public static function latest(int $serverId): ?array
    {
        return Db::one('SELECT * FROM reports WHERE server_id = ? ORDER BY id DESC LIMIT 1', [$serverId]);
    }

    /**
     * 最近 N 条（按时间正序返回，便于画图）。
     *
     * 排序与 X 轴都以「测量时间」为准：
     *   reported_at（服务器自报时间）优先，缺失时退回 received_at（服务端接收时间）。
     * 这样即使多条上报在同一分钟内到达，横轴也能区分先后。
     */
    public static function series(int $serverId, int $limit = 120): array
    {
        $limit = max(2, min(2000, $limit));
        $rows = Db::all(
            'SELECT id, received_at, reported_at, cpu_usage, mem_usage, swap_usage, disk_usage, net_up, net_down, load1
             FROM reports WHERE server_id = ?
             ORDER BY COALESCE(reported_at, received_at) DESC, id DESC
             LIMIT ' . $limit,
            [$serverId]
        );

        return array_reverse($rows);
    }

    public static function history(int $serverId, int $page, int $perPage = 25): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $total = (int) Db::value('SELECT COUNT(*) FROM reports WHERE server_id = ?', [$serverId]);

        $rows = Db::all(
            'SELECT id, received_at, cpu_usage, mem_usage, disk_usage, net_up, net_down
             FROM reports WHERE server_id = ?
             ORDER BY received_at DESC, id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$serverId]
        );

        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'per'   => $perPage,
        ];
    }

    public static function count(int $serverId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM reports WHERE server_id = ?', [$serverId]);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM reports WHERE id = ?', [$id]);
    }

    public static function deleteOlderThan(int $days): int
    {
        $days = max(1, $days);
        return Db::exec('DELETE FROM reports WHERE received_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }
}
