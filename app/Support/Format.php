<?php
declare(strict_types=1);

namespace App\Support;

final class Format
{
    /** 字节 -> 人类可读 */
    public static function bytes(?int $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        $decimals = $i === 0 ? 0 : $precision;
        $text = number_format($value, $decimals, '.', '');
        // 仅在含小数点时去掉多余的 0，避免 "0" 被裁成空串
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text . ' ' . $units[$i];
    }

    /** 字节/秒 -> 人类可读速率 */
    public static function speed(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }
        return self::bytes($bps) . '/s';
    }

    public static function percent(?float $value, int $precision = 1): string
    {
        if ($value === null) {
            return '—';
        }
        return number_format($value, $precision, '.', '') . '%';
    }

    /** 数据库 UTC 时间 -> 面板时区展示 */
    public static function datetime(?string $utc, string $format = 'Y-m-d H:i:s'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return '—';
        }
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
    }

    /** 相对时间，例如 “12 秒前” */
    public static function ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '从未上报';
        }
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return '—';
        }
        $seconds = time() - $dt->getTimestamp();
        if ($seconds < 0) {
            $seconds = 0;
        }
        if ($seconds < 10) {
            return '刚刚';
        }
        if ($seconds < 60) {
            return $seconds . ' 秒前';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' 分钟前';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' 小时前';
        }
        return intdiv($seconds, 86400) . ' 天前';
    }

    /** 秒 -> 运行时长 */
    public static function uptime(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            return sprintf('%d 天 %d 小时', $d, $h);
        }
        if ($h > 0) {
            return sprintf('%d 小时 %d 分', $h, $m);
        }
        return sprintf('%d 分钟', $m);
    }

    /**
     * 把服务器上报的原始时间字符串，转换成"该服务器时区下的可读时间 + UTC 偏移"。
     *
     * 例如上报 "2026-11-08T15:01:38Z"、时区 Asia/Shanghai，
     * 返回 "2026-11-08 23:01:38 (UTC+08:00)"。
     * 解析失败时原样返回，避免丢失信息。
     */
    public static function serverTime(string $raw, ?string $timezone): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $tz = null;
        if ($timezone !== null && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            $tz = new \DateTimeZone($timezone);
        }

        // 先按 UTC 解析绝对时刻（上报值可能带 Z / 偏移，也可能不带）
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $raw;
        }

        if ($tz === null) {
            return $dt->format('Y-m-d H:i:s') . ' (UTC)';
        }

        $local = $dt->setTimezone($tz);
        $offset = $local->getOffset();
        $sign = $offset >= 0 ? '+' : '-';
        $abs = abs($offset);
        $offsetText = sprintf('UTC%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));

        return $local->format('Y-m-d H:i:s') . ' (' . $offsetText . ')';
    }

    /** 秒 -> 更新频率（推荐上报间隔） */
    public static function interval(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        if ($seconds % 3600 === 0) {
            return intdiv($seconds, 3600) . ' 小时';
        }
        if ($seconds % 60 === 0) {
            return intdiv($seconds, 60) . ' 分钟';
        }
        return $seconds . ' 秒';
    }

    /** 带符号的时钟偏差 */
    public static function skew(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if (abs($seconds) < 2) {
            return '已同步';
        }

        $abs = abs($seconds);
        $sign = $seconds > 0 ? '+' : '-';

        if ($abs < 60) {
            return $sign . $abs . ' 秒';
        }
        if ($abs < 3600) {
            return $sign . intdiv($abs, 60) . ' 分钟';
        }
        if ($abs < 86400) {
            $h = intdiv($abs, 3600);
            $m = intdiv($abs % 3600, 60);
            return $sign . $h . ' 小时' . ($m > 0 ? ' ' . $m . ' 分' : '');
        }
        $d = intdiv($abs, 86400);
        $h = intdiv($abs % 86400, 3600);
        return $sign . $d . ' 天' . ($h > 0 ? ' ' . $h . ' 小时' : '');
    }
}
