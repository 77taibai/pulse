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
        if ($days <= 0) {
            return;
        }

        // 约 1/200 的请求触发一次，避免每次请求都扫表
        if (random_int(1, 200) !== 1) {
            return;
        }

        try {
            Report::deleteOlderThan($days);
        } catch (\Throwable $e) {
            error_log('[pulse prune] ' . $e->getMessage());
        }
    }
}
