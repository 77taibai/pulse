<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 轻量级表结构升级。
 *
 * 共享虚拟主机没有命令行、也不方便手动执行 SQL，因此把「补齐缺失字段」
 * 放在正常请求里自动完成。为了不依赖可写目录，这里不落盘记录版本号，
 * 而是直接以数据库的真实状态为准：每张表一次 SHOW COLUMNS，
 * 需要补列或清列时才执行 ALTER，全部就绪则只付出这一次查询。
 */
final class Schema
{
    /** 需要存在的字段：[表 => [字段 => 定义]] */
    private const COLUMNS = [
        // 每台服务器推荐的更新频率（秒），仅作为建议值下发给上报端
        'servers' => [
            'report_interval' => 'INT UNSIGNED NOT NULL DEFAULT 60',
        ],
    ];

    /**
     * 需要彻底清除的字段。
     *
     * 早期版本为了在详情页展示完整 KEY，额外存了一份可解密的 api_key_enc，
     * 以及一份明文前缀 api_key_hint。现在明文一律不落库，历史残留必须清掉，
     * 否则「服务端不可逆」只是一句空话。
     */
    private const OBSOLETE_COLUMNS = [
        'servers' => ['api_key_enc', 'api_key_hint'],
    ];

    /**
     * 需要直接删除的字段（非敏感数据，无需先清空）。
     *
     * raw_json 会保存完整原始报文，单行可达数 KB，长期占用大量空间；
     * 新版本只保存解析后的指标，历史列在升级时直接删除以释放空间。
     */
    private const DROP_COLUMNS = [
        'reports' => ['raw_json'],
    ];

    public static function ensure(): void
    {
        $tables = array_unique(array_merge(
            array_keys(self::COLUMNS),
            array_keys(self::OBSOLETE_COLUMNS),
            array_keys(self::DROP_COLUMNS)
        ));

        try {
            foreach ($tables as $table) {
                $existing = self::columns($table);
                if ($existing === []) {
                    continue; // 表还不存在（尚未安装），交给安装向导建表
                }

                foreach (self::COLUMNS[$table] ?? [] as $column => $definition) {
                    if (isset($existing[$column])) {
                        continue;
                    }
                    Db::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                }

                foreach (self::OBSOLETE_COLUMNS[$table] ?? [] as $column) {
                    if (isset($existing[$column])) {
                        self::purgeColumn($table, $column, $existing[$column]);
                    }
                }

                foreach (self::DROP_COLUMNS[$table] ?? [] as $column) {
                    if (isset($existing[$column])) {
                        self::dropColumn($table, $column);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 升级失败不影响正常使用，下次请求会重试
            error_log('[pulse schema] ' . $e->getMessage());
        }
    }

    /**
     * 清空并尝试删除历史遗留列。
     *
     * 删除列可能因为没有 ALTER 权限而失败，此时至少保证数据被清空 ——
     * 这才是「不可逆」的关键：即便整库被导出，也还原不出任何 KEY。
     *
     * @param bool $nullable 该列是否允许 NULL
     */
    private static function purgeColumn(string $table, string $column, bool $nullable): void
    {
        // 先抹掉数据：可空就置 NULL，否则置空串（两种都不会留下明文）
        try {
            Db::exec("UPDATE `{$table}` SET `{$column}` = " . ($nullable ? 'NULL' : "''"));
        } catch (\Throwable $e) {
            error_log('[pulse schema] 清理 ' . $table . '.' . $column . ' 失败：' . $e->getMessage());
            return;
        }

        // 再尝试删列，让「不存在」成为最强的保证
        try {
            Db::exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        } catch (\Throwable) {
            // 没有 ALTER 权限时保留空列，数据已清空，不影响使用
        }
    }

    /**
     * 删除不再需要的列。删除失败时记录日志，下次请求会重试。
     */
    private static function dropColumn(string $table, string $column): void
    {
        try {
            Db::exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        } catch (\Throwable $e) {
            error_log('[pulse schema] 删除 ' . $table . '.' . $column . ' 失败：' . $e->getMessage());
        }
    }

    /**
     * 现有字段：字段名 => 是否可为 NULL。
     *
     * @return array<string, bool>
     */
    private static function columns(string $table): array
    {
        try {
            $rows = Db::all('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns[$name] = strtoupper((string) ($row['Null'] ?? 'NO')) === 'YES';
        }

        return $columns;
    }
}
