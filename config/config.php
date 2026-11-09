<?php
/**
 * Pulse 默认配置。
 *
 * 请不要直接修改本文件 —— 安装向导会生成 config/config.local.php 覆盖这里的值。
 * 如需手动调整，修改 config/config.local.php 即可（它会被递归合并到本配置之上）。
 */
return [
    'app' => [
        'name' => 'Pulse',

        // 子目录部署时自动识别，一般无需填写。
        // 若自动识别不准（例如经过反向代理），可手动指定，如 '/pulse'
        'base_path' => null,

        // URL 风格：auto 自动探测 | rewrite 伪静态(推荐) | pathinfo (index.php/xxx)
        'url_style' => 'auto',

        // 面板展示时区（数据库内部统一使用 UTC 存储）
        'timezone' => 'Asia/Shanghai',

        // 超过多少秒没有上报即视为离线
        'offline_after' => 180,

        // 单个上报包最大字节数
        'max_payload' => 262144,

        // 生产环境请保持 false；开启后错误页会显示异常详情
        'debug' => false,

        // 上报记录保留天数；0 表示不按时间清理
        // 清理会在正常请求中按概率触发，不需要命令行或定时任务
        'retention_days' => 30,

        // 数据库（数据 + 索引）占用上限，单位 MB；0 表示不限制
        // 超出后会删除最旧的上报记录，并尝试 OPTIMIZE TABLE 回收空间
        'max_db_mb' => 0,
    ],

    // 由安装向导写入 config/config.local.php
    'db' => [
        'host' => '',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    // 由安装向导写入 config/config.local.php
    'auth' => [
        'username' => 'admin',
        'password_hash' => '',
    ],

    // OIDC 单点登录（可选），由安装向导写入 config/config.local.php
    'oidc' => [
        // 是否在登录页显示 OIDC 登录入口
        'enabled' => false,

        // 身份提供方 Issuer，例如 https://id.example.com
        // 程序会读取 {issuer}/.well-known/openid-configuration
        'issuer' => '',

        'client_id' => '',
        'client_secret' => '',

        // 请求的 scope，必须包含 openid
        'scopes' => 'openid profile email',

        // 允许登录的 sub 名单；为空时视为未启用
        'allowed_subs' => [],

        // 登录按钮文案
        'button_label' => '使用 OIDC 登录',

        // 在身份提供方登记的回调地址；留空时按当前部署自动生成
        'redirect_uri' => '',
    ],
];
