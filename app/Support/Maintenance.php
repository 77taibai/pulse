<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\Report;

/**
 * 数据清理。
 *
 * PHP 空间通常不提供计划任务，因此清理在正常请求中「按概率触发」，
 * 不需要任何命令行、定时任务或可写目录。
 */
final class Maintenance
{
    public static function maybePrune(): void
    {
        $days = (int) Config::get('app.retention_days', 0);
        $maxMb = (int) Config::get('app.max_db_mb', 0);
        if ($days <= 0 && $maxMb <= 0) {
            return;
        }

        // 约 1/200 的请求触发一次；配置了数据库上限时提高检查频率，减少超出幅度
        $chance = $maxMb > 0 ? 50 : 200;
        if (random_int(1, $chance) !== 1) {
            return;
        }

        try {
            if ($days > 0) {
                Report::deleteOlderThan($days);
            }
            if ($maxMb > 0) {
                Report::deleteToFit($maxMb * 1024 * 1024);
            }
        } catch (\Throwable $e) {
            error_log('[pulse prune] ' . $e->getMessage());
        }
    }
}
