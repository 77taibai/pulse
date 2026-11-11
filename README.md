# Pulse · 服务器状态监控

轻量级服务器状态监控面板，为 **PHP 虚拟主机 / 共享空间** 设计。

服务器端周期性上报状态，Web 面板实时展示 CPU、内存、硬盘与网络的可视化折线图。

- **运行环境**：PHP 8.0+（推荐 8.5）、MySQL 5.6+
- **零依赖**：无框架、无需 Composer、无 CDN，上传即用
- **安装方式**：浏览器安装向导，无需命令行
- **数据**：每次上报都完整入库，可回溯任意历史记录

---

## 一、部署（3 步）

### 1. 上传文件

把整个项目目录上传到网站根目录，或任意子目录（如 `/pulse`）。

```
public_html/            <- 网站根目录
├── index.php           <- 入口，也是唯一对外暴露的 PHP 文件
├── assets/             <- 样式、脚本、图标
├── app/                <- 程序代码
├── config/             <- 配置（安装时自动生成 config.local.php）
├── database/           <- 表结构
├── documentation/      <- API 文档源
└── .htaccess           <- Apache 配置（Nginx 见下文）
```

> **路径会自动识别**。装在根目录时是 `example.com/`，装在子目录时是 `example.com/pulse/`，无需手工配置。

### 2. 打开浏览器，按向导安装

访问你的站点地址，会自动进入安装向导：

```
http://你的域名/            （根目录部署）
http://你的域名/pulse/      （子目录部署）
```

向导会依次完成：

1. **环境检查** —— PHP 版本、PDO MySQL / mbstring / json 扩展、`config` 目录可写权限
2. **填写数据库信息** —— 主机、端口、库名、用户名、密码
   （库不存在时会尝试自动创建；无权限时请先在主机面板手动建库）
3. **设置管理员账号** —— 用户名 + 密码
4. **自动建表** —— 创建 `servers` 与 `reports` 两张表
5. **自动识别伪静态** —— 支持则用 `/servers/1`，不支持自动退回 `/index.php/servers/1`

安装完成后生成 `config/config.local.php`（权限 0640）。

> 程序运行期不写任何文件（没有缓存目录、没有状态文件），
> 因此除了 `config/` 需要在安装时可写之外，其余目录都可以是只读的。


> 需要重装时：删除 `config/config.local.php`，刷新页面即可重新进入向导。

### 3. 登录使用

用刚设置的管理员账号登录。进入「添加服务器」创建第一台服务器，拿到 API KEY 后配置到服务器端上报程序。

---

## 二、服务器端上报

在面板创建服务器后得到 API KEY（形如 `pk_...`）。**KEY 只在创建服务器或重置 KEY 时显示一次**，
请当场复制保存到上报程序配置里，之后无法再查看。

```bash
curl -X POST "http://你的域名/api/v1/report" \
  -H "Authorization: Bearer pk_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "timezone": "Asia/Shanghai",
    "server_time": "2026-05-20T14:32:10+08:00",
    "cpu":    {"model": "Intel Xeon", "cores": 8, "usage": 23.5},
    "memory": {"total": 8589934592, "used": 3221225472},
    "swap":   {"total": 2147483648, "used": 0},
    "disk":   {"total": 107374182400, "used": 53687091200},
    "network": {"up": 125000, "down": 890000}
  }'
```

完整字段说明与容错规则见面板内的「API 文档」页（`/api-docs`），
源文件位于 `documentation/API.md`（标准 Markdown，可直接编辑；文档页右上角提供「下载 .md」）。

### 更新频率

添加或编辑服务器时可以设置「更新频率」（10 秒 ~ 1 小时）。该值会随自检接口
（`GET /api/v1/server` 的 `report_interval`）返回，上报状态接口的响应里也会带上
`report_interval` 与按此推算的 `next_report_at`，供上报程序参考。

它只是推荐值：面板不校验实际上报间隔，提前或延后上报都不会被拒收。

在线 / 离线判定会随该频率动态调整：允许错过一次上报，阈值取
`max(offline_after, 上报间隔 × 2)`。因此把更新频率调到 30 分钟时，
不会被 3 分钟的兜底阈值误判为离线。

### 容错能力

监控端上报不全也不会报错：

- 所有字段均可选，缺失存为 `NULL`，图表断开而不是画成 0
- 支持扁平写法（`cpu_usage`）与嵌套写法（`cpu.usage`）
- 支持带单位的字符串：`"23.5%"`、`"8GB"`、`"1.5MB/s"`、`"100Mbps"`

### 由面板计算的字段

以下数值统一由面板后端计算，上报端不需要也不应该自己算（传了会被忽略）：

| 字段 | 计算方式 |
| --- | --- |
| `memory.usage` | `used ÷ total × 100` |
| `swap.usage` | `used ÷ total × 100` |
| `disk.usage` | `used ÷ total × 100` |
| `disk.free` | `total - used` |
| `load.1` / `load.5` / `load.15` | 该服务器最近 1 / 5 / 15 分钟内 `cpu.usage` 的平均值 |

好处是所有服务器口径一致，不会出现「同一台机器两个脚本算出两个使用率」。

---

## 三、Web 服务器配置

### Apache（大多数虚拟主机）

**整个项目只有根目录一个 `.htaccess`**，子目录不再需要，通常也无需改动。它负责：

- 伪静态重写（`/servers/1` → `index.php`）
- 禁止访问 `app/` `config/` `database/` `documentation/` 等内部目录
- 禁止下载 `.sql` `.md` `.log` 等敏感后缀
- 关闭目录列表、设置静态资源缓存与安全响应头

`FilesMatch` 与 `RewriteRule` 的作用范围都包含所有子目录，因此根目录一个文件即可覆盖全站。

若主机禁用了 `.htaccess` 或 `mod_rewrite`，程序会自动退回 `index.php/xxx` 形式，
功能不受影响；同时 `index.php` 内部还有一层独立的路径拦截，作为不依赖服务器配置的兜底。

### Nginx

Nginx 不读 `.htaccess`，需要把下面的配置加到站点配置里：

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /home/user/public_html;
    index index.php;

    # 伪静态：真实文件直接返回，其余交给入口
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ★ 关键：禁止访问敏感目录与文件（Nginx 下必须手写，否则会泄露）
    location ~ ^/(app|config|database|documentation)/ {
        deny all;
        return 403;
    }
    location ~* \.(sql|md|log|ini|lock|bak|dist|swp|secret|txt)$ {
        deny all;
        return 403;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~* \.(css|js|svg)$ {
        expires 7d;
        add_header Cache-Control "public";
    }
}
```

> **注意**：程序本身也在 `index.php` 里对敏感路径做了拦截，但 Nginx 会在 PHP 之前就把静态文件返回，
> 所以上面这段 `deny` 配置在 Nginx 环境下是**必须的**。

---

## 四、目录结构

```
pulse/
├── index.php                 入口（唯一对外 PHP）
├── .htaccess                 Apache 配置
├── assets/                   前端资源
│   ├── app.css
│   ├── app.js
│   └── favicon.svg
├── app/
│   ├── Api/                  上报接口与异常
│   ├── Controllers/          面板 / 安装 / 文档控制器
│   ├── Models/               servers / reports 数据访问
│   ├── Support/              配置、数据库、URL、请求响应、格式化、容错解析
│   ├── Views/                视图模板
│   ├── autoload.php
│   └── bootstrap.php
├── config/
│   ├── config.php            默认配置
│   ├── config.local.php      安装向导生成（含敏感信息）
│   └── routes.php            路由表
├── database/schema.php       表结构（PHP 数组，避免 .sql 被下载）
└── documentation/API.md      API 文档（标准 Markdown，可在线预览也可下载）
```

---

## 五、日常维护

所有操作都在浏览器内完成，**不需要 SSH、命令行或计划任务**。

### 自动清理历史数据

保留天数和数据库空间上限都可以在安装向导中设置，也可在 `config/config.local.php` 的 `app` 段调整：

```php
'app' => [
    'retention_days' => 30,   // 只保留 30 天，0 表示不按时间清理
    'max_db_mb' => 500,       // 数据库上限 500 MB，0 表示不限制
],
```

程序会在正常访问中按概率分批清理：

- 先删除超过保留天数的记录；
- 数据库（数据 + 索引）超过 `max_db_mb` 时，删除最旧的上报记录压到上限的 85% 以下，并尝试 `OPTIMIZE TABLE` 回收磁盘空间（无权限时删除的空间仍会被后续写入复用）。

无需任何定时任务或可写目录。

### 删除服务器

在面板首页点进服务器，右上角「删除」即可连同历史数据一起删除。

### 重装

删除 `config/config.local.php`，刷新页面重新进入安装向导。
（数据库里的数据会保留，安装时若表已存在会跳过创建。）

---

## 六、数据库表

**servers**

| 字段 | 说明 |
| --- | --- |
| `name` / `provider` / `location` / `note` | 服务器信息 |
| `api_key_hash` | API KEY 的 SHA-256（唯一索引，认证只依赖它，不可逆） |
| `report_interval` | 更新频率（秒），作为推荐上报间隔下发给上报端，默认 60 |
| `last_seen_at` / `last_report_id` | 最近一次上报时间与记录 ID，用于首页快速展示 |

**reports**

每次上报一行，包含 `received_at`（服务端接收时间，UTC）、`reported_at`（服务器自报时间）、
`timezone`、`clock_skew`（时钟偏差秒数），以及 CPU / 内存 / 交换 / 硬盘 / 网络等指标。
原始报文不再入库（旧版本遗留的 `raw_json` 列会在升级时自动删除），以节省存储空间。

其中 `mem_usage` / `swap_usage` / `disk_usage` / `load1` / `load5` / `load15` 由面板在入库时计算
（负载为最近 1/5/15 分钟 `cpu_usage` 的均值），不取上报端的值。

---

## 七、安全说明

- 密码使用 `password_hash()`（bcrypt）存储，登录做时序安全比较
- 所有表单提交校验 CSRF Token，登录成功后重建 Session ID
- 全部 SQL 使用 PDO 预处理，杜绝注入
- 所有输出经 `htmlspecialchars` 转义，防止 XSS
- API KEY 只存 SHA-256 哈希，服务端**不存在任何可还原明文的副本**，接口按 KEY 精确定位服务器，不存在越权读取他人数据
- API KEY 明文仅在「创建服务器 / 重置 KEY」后渲染一次，随即从会话中删除（10 分钟未查看自动作废），刷新或离开页面后无法再查看
- 旧版本遗留的 `api_key_enc`（可逆加密副本）与 `api_key_hint`（明文前缀）会在升级时被清空并尝试删列，仅保留不可逆哈希
- **敏感文件三重防护**：
  1. `.htaccess` / Nginx `deny`（Web 服务器层）
  2. `index.php` 中的路径拦截（PHP 层，不依赖服务器配置）
  3. 文档与表结构放在被拦截的 `documentation/` `database/` 目录内，文档只通过 `/api-docs`（预览）与 `/api-docs/download`（下载）两个入口输出
- 响应头附带 `X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`
- 建议生产环境启用 HTTPS，并将 `app.debug` 保持为 `false`

---

## 八、常见问题

**Q：访问首页显示 403 或 404？**
A：确认 `index.php` 已上传到网站根目录，且 PHP 版本 ≥ 8.0。子目录部署请访问 `域名/子目录/`。

**Q：安装向导提示 `config` 目录不可写？**
A：在主机面板把 `config` 目录权限改为 755 或 777，安装完成后建议改回 755。

**Q：伪静态不生效，URL 变成 `index.php/servers/1`？**
A：这是正常的降级行为，功能完全不受影响。若想要简洁 URL，请让主机开启 `mod_rewrite`，
或把 `config.local.php` 里的 `'url_style'` 改为 `'rewrite'`。

**Q：图表没有数据？**
A：检查服务器端上报是否返回 `201`。可用 `GET /api/v1/server` 验证 KEY 是否正确。

**Q：忘记管理员密码？**
A：删除 `config/config.local.php` 重新安装（会丢失配置，但数据库数据保留），
或直接在数据库里手动更新 `config.local.php` 的 `auth.password_hash`。
