<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 上报数据归一化与容错处理。
 * 监控端可能只上报部分字段、类型不统一（数字/字符串/带单位）或使用不同命名，
 * 这里统一解析为数据库字段，任何缺失或异常都退化为 null 而不是报错。
 *
 * 注意：内存 / 虚拟内存 / 硬盘的「使用率」以及 1/5/15 分钟平均负载
 * 一律由面板（本后端）根据上报的原始容量、历史 CPU 使用率自行计算，
 * 上报端即使传了这些字段也会被丢弃，避免各脚本口径不一致导致图表失真。
 */
final class Normalizer
{
    /** 数字字段别名表：目标字段 => 可能的来源键名（含嵌套路径）。仅包含上报端提供的原始数值 */
    private const NUMBER_ALIASES = [
        'cpu_usage'  => ['cpu_usage', 'cpu.usage', 'cpu.usage_percent', 'cpu.percent', 'cpu.used_percent', 'cpu.load_percent'],
        'cpu_cores'  => ['cpu_cores', 'cpu.cores', 'cpu.core_count', 'cpu.core', 'cpu.cpu_count', 'cpu.count'],
        'mem_total'  => ['mem_total', 'memory.total', 'memory.mem_total', 'memory.ram_total', 'ram.total', 'memory.physical.total'],
        'mem_used'   => ['mem_used', 'memory.used', 'memory.mem_used', 'memory.ram_used', 'ram.used', 'memory.physical.used'],
        'swap_total' => ['swap_total', 'memory.swap_total', 'swap.total', 'memory.virtual.total', 'memory.swap.total'],
        'swap_used'  => ['swap_used', 'memory.swap_used', 'swap.used', 'memory.virtual.used', 'memory.swap.used'],
        'disk_total' => ['disk_total', 'disk.total', 'disk.size', 'disk.storage.total'],
        'disk_used'  => ['disk_used', 'disk.used', 'disk.storage.used'],
        'net_up'     => ['net_up', 'network.up', 'network.upload', 'network.tx', 'network.tx_speed', 'net.upload', 'net.up_speed', 'network.up_speed'],
        'net_down'   => ['net_down', 'network.down', 'network.download', 'network.rx', 'network.rx_speed', 'net.download', 'net.down_speed', 'network.down_speed'],
        'uptime'     => ['uptime', 'system.uptime', 'boot.uptime', 'uptime_seconds'],
    ];

    private const STRING_ALIASES = [
        'hostname'  => ['hostname', 'system.hostname', 'host.name', 'machine'],
        'os'        => ['os', 'system.os', 'system.os_name', 'platform', 'os_name'],
        'kernel'    => ['kernel', 'system.kernel', 'system.kernel_version', 'uname'],
        'arch'      => ['arch', 'system.arch', 'system.architecture', 'architecture'],
        'cpu_model' => ['cpu_model', 'cpu.model', 'cpu.name', 'cpu.model_name', 'cpu.brand'],
        'timezone'  => ['timezone', 'tz', 'system.timezone'],
        'server_time_raw' => ['server_time', 'time', 'system.time', 'system.server_time', 'datetime', 'timestamp'],
    ];

    /** 处理后的结果 */
    public static function report(array $payload, ?string $ip): array
    {
        $payload = self::flattenSafe($payload);

        $timezone = self::string($payload, self::STRING_ALIASES['timezone']);
        if ($timezone !== null && !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = null;
        }

        [$reportedAt, $serverTimeRaw] = self::parseServerTime($payload, $timezone);

        $data = [
            'reported_at'     => $reportedAt,
            'timezone'        => $timezone,
            'server_time_raw' => $serverTimeRaw,
            'clock_skew'      => $reportedAt === null ? null : (time() - (int) (new \DateTimeImmutable($reportedAt, new \DateTimeZone('UTC')))->getTimestamp()),
            'ip'              => $ip,
        ];

        foreach (self::STRING_ALIASES as $field => $aliases) {
            if ($field === 'timezone' || $field === 'server_time_raw') {
                continue;
            }
            $value = self::string($payload, $aliases);
            $data[$field] = $value === null ? null : mb_substr($value, 0, self::maxLen($field));
        }

        foreach (self::NUMBER_ALIASES as $field => $aliases) {
            $data[$field] = self::number($payload, $aliases);
        }

        // 整数型字段
        foreach (['cpu_cores', 'mem_total', 'mem_used', 'swap_total', 'swap_used', 'disk_total', 'disk_used', 'net_up', 'net_down', 'uptime'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = max(0, (int) round((float) $data[$field]));
            }
        }
        if ($data['cpu_cores'] !== null) {
            $data['cpu_cores'] = min(4096, $data['cpu_cores']);
        }
        if ($data['uptime'] !== null) {
            $data['uptime'] = min(1_000_000_000, $data['uptime']);
        }

        // CPU 使用率仍由上报端采集（面板无法自行获得），只做裁剪
        if ($data['cpu_usage'] !== null) {
            $data['cpu_usage'] = round(min(100.0, max(0.0, (float) $data['cpu_usage'])), 2);
        }

        // —— 使用率与剩余空间一律由面板计算，上报端传的值直接忽略 ——
        if ($data['disk_used'] !== null && $data['disk_total'] !== null) {
            $data['disk_used'] = min($data['disk_used'], $data['disk_total']);
        }
        $data['disk_free'] = ($data['disk_total'] !== null && $data['disk_used'] !== null)
            ? max(0, $data['disk_total'] - $data['disk_used'])
            : null;

        $data['mem_usage'] = self::ratio($data['mem_used'], $data['mem_total']);
        $data['swap_usage'] = self::ratio($data['swap_used'], $data['swap_total']);
        $data['disk_usage'] = self::ratio($data['disk_used'], $data['disk_total']);

        // 负载由面板按最近 1/5/15 分钟的 CPU 使用率均值算出（见 App\Models\Report::refreshLoad），
        // 这里先占位，入库后回填。
        $data['load1'] = null;
        $data['load5'] = null;
        $data['load15'] = null;

        // 网络字段若缺失，视为未上报（保持 null），不要臆造 0
        foreach (['net_up', 'net_down'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = min(PHP_INT_MAX >> 20, $data[$field]);
            }
        }

        return $data;
    }

    /** 已用 / 总量 -> 百分比（保留两位），任一项缺失或总量为 0 时返回 null */
    private static function ratio(?int $used, ?int $total): ?float
    {
        if ($used === null || $total === null || $total <= 0) {
            return null;
        }

        return round(min(100.0, max(0.0, $used / $total * 100)), 2);
    }

    private static function maxLen(string $field): int
    {
        return match ($field) {
            'hostname' => 191,
            'os', 'kernel' => 128,
            'arch' => 32,
            'cpu_model' => 191,
            default => 191,
        };
    }

    /** 支持点号路径读取 */
    private static function pick(array $payload, string $path): mixed
    {
        if (array_key_exists($path, $payload)) {
            return $payload[$path];
        }
        $node = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** 预先把嵌套结构展开成 "a.b.c" 扁平键，避免大小写/嵌套差异 */
    private static function flattenSafe(array $payload): array
    {
        $flat = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $key = is_string($key) ? $key : (string) $key;
                $full = $prefix === '' ? $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $walk($value, $full);
                } else {
                    $flat[$full] = $value;
                    $lower = strtolower($full);
                    if (!array_key_exists($lower, $flat)) {
                        $flat[$lower] = $value;
                    }
                }
            }
        };
        $walk($payload, '');

        return $flat;
    }

    private static function string(array $payload, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            $value = self::pick($payload, $alias);
            if ($value === null || is_array($value)) {
                continue;
            }
            if (is_bool($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    private static function number(array $payload, array $aliases): ?float
    {
        foreach ($aliases as $alias) {
            $value = self::pick($payload, $alias);
            if ($value === null || is_array($value)) {
                continue;
            }
            $number = self::toNumber($value);
            if ($number !== null) {
                return $number;
            }
        }
        return null;
    }

    /**
     * 容错解析：支持 int/float/数字字符串/"12.5%"/"1.5GB"/"300 MB/s"/"10Mbps"
     */
    private static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_bool($value) || $value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if (!preg_match('/-?\d+(?:[.,]\d+)?/', $text, $m)) {
            return null;
        }
        $number = (float) str_replace(',', '.', $m[0]);

        // 百分比
        if (str_contains($text, '%')) {
            return $number;
        }

        return self::applyUnit($number, $text);
    }

    /**
     * 解析单位后缀：
     *  - 速率：Mb/s、Mbps、MB/s、KB/s、B/s（bit 单位按 1000 进制并 ÷8，Byte 单位按 1024 进制）
     *  - 容量：B、KB、MB、GB、TB、KiB、MiB、GiB
     *  - 无单位：原样返回
     */
    private static function applyUnit(float $number, string $text): float
    {
        // 只取末尾的单位片段；前缀大小写都接受，但保留 B(字节) 与 b(比特) 的区别
        if (!preg_match('/([KMGTPEkmgtpe]?)(i?)([Bb])(ps|\/s|\/sec|s)?\s*$/', $text, $u)) {
            return $number;
        }

        $prefix = strtoupper($u[1] ?? '');
        $letter = $u[3] ?? '';
        $suffix = strtolower($u[4] ?? '');

        $power = match ($prefix) {
            'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6, default => 0,
        };

        // 小写 b 或带 ps 后缀，视为比特速率
        if ($suffix === 'ps' || $letter === 'b') {
            // 比特单位使用十进制前缀（1 Mbps = 1,000,000 bit/s），再换算为字节
            return $number * (1000 ** $power) / 8;
        }

        // 字节单位使用二进制前缀
        return $number * (1024 ** $power);
    }

    /**
     * 解析服务器时间，返回 [UTC datetime 字符串|null, 原始值|null]
     * 支持 ISO8601、Unix 时间戳（秒/毫秒）、"Y-m-d H:i:s"（结合上报时区）
     */
    private static function parseServerTime(array $payload, ?string $timezone): array
    {
        $raw = self::pick($payload, 'server_time')
            ?? self::pick($payload, 'time')
            ?? self::pick($payload, 'system.server_time')
            ?? self::pick($payload, 'system.time')
            ?? self::pick($payload, 'timestamp')
            ?? self::pick($payload, 'datetime');

        if ($raw === null || is_array($raw)) {
            return [null, null];
        }

        $rawText = mb_substr(trim((string) $raw), 0, 64);
        if ($rawText === '') {
            return [null, null];
        }

        // 纯数字：Unix 时间戳
        if (preg_match('/^\d{9,14}$/', $rawText)) {
            $ts = (int) $rawText;
            if ($ts > 99_999_999_999) {
                $ts = intdiv($ts, 1000);
            }
            if ($ts > 0) {
                return [gmdate('Y-m-d H:i:s', $ts), $rawText];
            }
        }

        $tz = $timezone !== null ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');

        // 带时区信息或 ISO8601
        foreach ([\DateTimeImmutable::ATOM, 'Y-m-d\TH:i:s.uP', 'Y-m-d H:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y/m/d H:i:s', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $rawText, $tz);
            if ($dt instanceof \DateTimeImmutable) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return [$dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $rawText];
                }
            }
        }

        try {
            $dt = new \DateTimeImmutable($rawText, $tz);
            return [$dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $rawText];
        } catch (\Throwable) {
            return [null, $rawText];
        }
    }
}
