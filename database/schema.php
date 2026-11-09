<?php
declare(strict_types=1);

/**
 * 数据表结构定义。
 *
 * 用 PHP 数组而不是 .sql 文件，是为了避免共享主机上 .sql 被当作静态文件直接下载
 * （.htaccess 只对 Apache 生效，Nginx 或未启用 .htaccess 的主机会泄露）。
 *
 * 兼容 MySQL 5.6+：不使用 JSON 类型、不使用生成列、不使用 utf8mb4_0900 排序规则。
 * 所有时间字段统一按 UTC 存储，展示时再转换为面板时区。
 */

return [
    'servers' => "CREATE TABLE IF NOT EXISTS `servers` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(100) NOT NULL,
        `provider`       VARCHAR(100) DEFAULT NULL,
        `location`       VARCHAR(100) DEFAULT NULL,
        `api_key_hash`   CHAR(64)     NOT NULL,
        `note`           VARCHAR(255) DEFAULT NULL,
        `report_interval` INT UNSIGNED NOT NULL DEFAULT 60,
        `created_at`     DATETIME     NOT NULL,
        `last_seen_at`   DATETIME     DEFAULT NULL,
        `last_report_id` BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_api_key_hash` (`api_key_hash`),
        KEY `idx_last_seen` (`last_seen_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'reports' => "CREATE TABLE IF NOT EXISTS `reports` (
        `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `server_id`       INT UNSIGNED NOT NULL,
        `received_at`     DATETIME     NOT NULL,
        `reported_at`     DATETIME     DEFAULT NULL,
        `timezone`        VARCHAR(64)  DEFAULT NULL,
        `server_time_raw` VARCHAR(64)  DEFAULT NULL,
        `clock_skew`      INT          DEFAULT NULL,
        `uptime`          BIGINT       DEFAULT NULL,
        `hostname`        VARCHAR(191) DEFAULT NULL,
        `os`              VARCHAR(128) DEFAULT NULL,
        `kernel`          VARCHAR(128) DEFAULT NULL,
        `arch`            VARCHAR(32)  DEFAULT NULL,
        `cpu_model`       VARCHAR(191) DEFAULT NULL,
        `cpu_cores`       SMALLINT UNSIGNED DEFAULT NULL,
        `cpu_usage`       DECIMAL(5,2) DEFAULT NULL,
        `mem_total`       BIGINT       DEFAULT NULL,
        `mem_used`        BIGINT       DEFAULT NULL,
        `mem_usage`       DECIMAL(5,2) DEFAULT NULL,
        `swap_total`      BIGINT       DEFAULT NULL,
        `swap_used`       BIGINT       DEFAULT NULL,
        `swap_usage`      DECIMAL(5,2) DEFAULT NULL,
        `disk_total`      BIGINT       DEFAULT NULL,
        `disk_used`       BIGINT       DEFAULT NULL,
        `disk_free`       BIGINT       DEFAULT NULL,
        `disk_usage`      DECIMAL(5,2) DEFAULT NULL,
        `net_up`          BIGINT       DEFAULT NULL,
        `net_down`        BIGINT       DEFAULT NULL,
        `load1`           DECIMAL(8,2) DEFAULT NULL,
        `load5`           DECIMAL(8,2) DEFAULT NULL,
        `load15`          DECIMAL(8,2) DEFAULT NULL,
        `ip`              VARCHAR(45)  DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_server_id` (`server_id`, `id`),
        KEY `idx_server_time` (`server_id`, `received_at`),
        KEY `idx_received` (`received_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
