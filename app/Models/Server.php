<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Config;
use App\Support\Db;

/**
 * servers 数据访问。
 *
 * API KEY 只以 SHA-256 哈希形式入库，服务端不存在任何可用于还原明文的副本，
 * 因此即便数据库被完整导出也无法反推出 KEY。明文仅在创建 / 重置的那一刻
 * 返回给当前会话一次（见 App\Support\KeyReveal）。
 */
final class Server
{
    /** 更新频率（秒）的取值范围与默认值 */
    public const INTERVAL_MIN = 10;
    public const INTERVAL_MAX = 3600;
    public const INTERVAL_DEFAULT = 60;

    public static function all(): array
    {
        return Db::all('SELECT * FROM servers ORDER BY id ASC');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM servers WHERE id = ?', [$id]);
    }

    public static function findByApiKey(string $apiKey): ?array
    {
        return Db::one('SELECT * FROM servers WHERE api_key_hash = ?', [self::hashKey($apiKey)]);
    }

    public static function hashKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    private static function generateKey(): string
    {
        return 'pk_' . bin2hex(random_bytes(24));
    }

    /**
     * 把任意输入规整为合法的更新频率（秒）。
     *
     * 该值只是「建议上报间隔」，不参与任何强制校验，
     * 因此非法输入一律退回默认值而不是报错。
     */
    public static function normalizeInterval(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_numeric($value)) {
            return self::INTERVAL_DEFAULT;
        }

        $seconds = (int) round((float) $value);
        if ($seconds < self::INTERVAL_MIN) {
            return self::INTERVAL_MIN;
        }
        if ($seconds > self::INTERVAL_MAX) {
            return self::INTERVAL_MAX;
        }

        return $seconds;
    }

    /** 读取某台服务器的更新频率（秒） */
    public static function interval(array $server): int
    {
        return self::normalizeInterval($server['report_interval'] ?? null);
    }

    /**
     * 判定离线的阈值（秒）。
     *
     * 上报间隔是可配置的（10 秒 ~ 1 小时），如果只用一个固定阈值，
     * 间隔较大的服务器会在还没到下次上报时间时就被误判为离线。
     * 因此以该服务器自身的上报间隔为准：允许错过一次上报（2 倍间隔），
     * 并保证不低于全局 offline_after 兜底值，兼容原有小间隔场景。
     */
    public static function offlineThreshold(array $server): int
    {
        $floor = (int) Config::get('app.offline_after', 180);
        $interval = self::interval($server);

        return max($floor, $interval * 2);
    }

    /** 依据最近一次上报时间与阈值判断是否在线 */
    public static function isOnline(?string $lastSeenUtc, int $threshold): bool
    {
        if ($lastSeenUtc === null || $lastSeenUtc === '') {
            return false;
        }

        $seen = strtotime($lastSeenUtc . ' UTC');
        if ($seen === false) {
            return false;
        }

        return (time() - $seen) <= $threshold;
    }

    /**
     * 创建服务器，返回 [id, 明文 key]。
     *
     * 明文不落库，调用方必须立刻把它交给用户（一次性展示），之后无法再取回。
     */
    public static function create(
        string $name,
        ?string $provider,
        ?string $location,
        ?string $note,
        int $reportInterval = self::INTERVAL_DEFAULT
    ): array {
        $apiKey = self::generateKey();

        $id = Db::insert(
            'INSERT INTO servers (name, provider, location, api_key_hash, note, report_interval, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                mb_substr($name, 0, 100),
                $provider !== null && $provider !== '' ? mb_substr($provider, 0, 100) : null,
                $location !== null && $location !== '' ? mb_substr($location, 0, 100) : null,
                self::hashKey($apiKey),
                $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                self::normalizeInterval($reportInterval),
                gmdate('Y-m-d H:i:s'),
            ]
        );

        return [$id, $apiKey];
    }

    public static function update(
        int $id,
        string $name,
        ?string $provider,
        ?string $location,
        ?string $note,
        int $reportInterval = self::INTERVAL_DEFAULT
    ): void {
        Db::exec(
            'UPDATE servers SET name = ?, provider = ?, location = ?, note = ?, report_interval = ? WHERE id = ?',
            [
                mb_substr($name, 0, 100),
                $provider !== null && $provider !== '' ? mb_substr($provider, 0, 100) : null,
                $location !== null && $location !== '' ? mb_substr($location, 0, 100) : null,
                $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                self::normalizeInterval($reportInterval),
                $id,
            ]
        );
    }

    /** 轮换 Key，返回新的明文 key（同样只允许展示一次） */
    public static function rotateKey(int $id): string
    {
        $apiKey = self::generateKey();
        Db::exec(
            'UPDATE servers SET api_key_hash = ? WHERE id = ?',
            [self::hashKey($apiKey), $id]
        );

        return $apiKey;
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM reports WHERE server_id = ?', [$id]);
        Db::exec('DELETE FROM servers WHERE id = ?', [$id]);
    }

    public static function touch(int $id, ?string $lastSeenUtc, ?int $reportId): void
    {
        Db::exec(
            'UPDATE servers SET last_seen_at = ?, last_report_id = ? WHERE id = ?',
            [$lastSeenUtc, $reportId, $id]
        );
    }

    /** 首页列表：服务器 + 最近一次上报 */
    public static function overview(): array
    {
        $rows = Db::all(
            'SELECT s.*, r.received_at AS r_received_at, r.reported_at AS r_reported_at,
                    r.cpu_usage, r.cpu_cores, r.cpu_model,
                    r.mem_total, r.mem_used, r.mem_usage,
                    r.swap_total, r.swap_used, r.swap_usage,
                    r.disk_total, r.disk_used, r.disk_free, r.disk_usage,
                    r.net_up, r.net_down, r.uptime, r.hostname, r.os, r.timezone,
                    r.clock_skew, r.ip, r.load1
             FROM servers s
             LEFT JOIN reports r ON r.id = s.last_report_id
             ORDER BY s.id ASC'
        );

        foreach ($rows as &$row) {
            $row['online'] = self::isOnline($row['last_seen_at'] ?? null, self::offlineThreshold($row));
        }
        unset($row);

        return $rows;
    }
}
