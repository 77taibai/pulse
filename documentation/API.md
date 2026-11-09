# Pulse 服务器监控 API

面向服务器端上报程序的对接文档。所有接口均为 JSON，编码 UTF-8。

- **Base URL**：`http://<你的面板域名>/api/v1`
- **上报接口**：`POST /api/v1/report`
- **认证方式**：`Authorization: Bearer <API_KEY>`（也支持 `X-API-Key` 头或 `?key=` 参数）

API KEY 在面板「添加服务器」时生成，只在创建或重置 KEY 时显示一次，请当场复制保存，不要泄露给无关人员。

---

## 1. 认证

每个请求必须携带服务器的 API KEY，任选一种：

| 方式 | 示例 |
| --- | --- |
| 请求头（推荐） | `Authorization: Bearer pk_xxxxxxxxxxxx` |
| 自定义头 | `X-API-Key: pk_xxxxxxxxxxxx` |
| 查询参数 | `POST /api/v1/report?key=pk_xxxxxxxxxxxx` |

KEY 无效或缺失返回 `401`。

---

## 2. 上报状态

```
POST /api/v1/report
Content-Type: application/json
Authorization: Bearer <API_KEY>
```

### 2.1 完整示例

```json
{
  "timezone": "Asia/Shanghai",
  "server_time": "2026-05-20T14:32:10+08:00",
  "hostname": "web-01",
  "os": "Ubuntu 22.04",
  "kernel": "5.15.0-91-generic",
  "arch": "x86_64",
  "uptime": 864000,
  "cpu": {
    "model": "Intel Xeon E5-2680 v4",
    "cores": 8,
    "usage": 23.5
  },
  "memory": {
    "total": 8589934592,
    "used": 3221225472
  },
  "swap": {
    "total": 2147483648,
    "used": 0
  },
  "disk": {
    "total": 107374182400,
    "used": 53687091200
  },
  "network": {
    "up": 125000,
    "down": 890000
  }
}
```

### 2.2 上报字段说明

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `timezone` | string | 否 | 服务器时区，如 `Asia/Shanghai`。用于解析不带时区的 `server_time` |
| `server_time` | string \| int | 否 | 服务器当前时间。支持 ISO8601、`Y-m-d H:i:s`，也接受 Unix 时间戳（秒或毫秒） |
| `hostname` | string | 否 | 主机名 |
| `os` | string | 否 | 操作系统名称 |
| `kernel` | string | 否 | 内核版本 |
| `arch` | string | 否 | CPU 架构 |
| `uptime` | int | 否 | 已运行秒数 |
| `cpu.model` | string | 否 | CPU 型号 |
| `cpu.cores` | int | 否 | 核心数 |
| `cpu.usage` | number | 否 | CPU 使用率，百分比 0–100 |
| `memory.total` | int | 否 | 物理内存总量（字节） |
| `memory.used` | int | 否 | 已用物理内存（字节） |
| `swap.total` | int | 否 | 虚拟内存（Swap）总量（字节） |
| `swap.used` | int | 否 | 已用虚拟内存（字节） |
| `disk.total` | int | 否 | 硬盘总容量（字节） |
| `disk.used` | int | 否 | 已用空间（字节） |
| `network.up` | int | 否 | 上行速度（字节/秒） |
| `network.down` | int | 否 | 下行速度（字节/秒） |

> **所有字段都是可选的**，能上报多少就传多少。缺失字段按空值处理，图表会断开而不是画成 0。
> 一次上报中至少需要包含一个可识别的监控数值（CPU / 内存 / 硬盘 / 网络任一项），否则返回 `422`。

### 2.3 容错能力（重要）

为了兼容各种采集脚本，以下写法都会被正确识别：

1. **扁平写法**：`cpu_usage`、`mem_total`、`disk_used`、`net_up`、`net_down` 等
2. **嵌套写法**：`cpu.usage`、`memory.total`、`disk.used`、`network.up`
3. **带单位的字符串**：
   - 百分比：`"23.5%"` → `23.5`
   - 容量：`"8GB"` / `"512 MB"` → 字节数
   - 速率：`"1.5MB/s"`、`"100Mbps"`（bit 单位自动 ÷8 换算为字节/秒）
4. **包装形式**：`{"data": {...}}` 或表单 `data=<json>` 均可
5. 数值越界会被裁剪（使用率限制在 0–100，已用量不会超过总量，负数归零）

### 2.4 响应

成功（`201 Created`）：

```json
{
  "ok": true,
  "report_id": 1024,
  "server": "web-01",
  "received_at": "2026-05-20T06:32:11+00:00",
  "report_interval": 60,
  "next_report_at": "2026-05-20T06:33:11+00:00"
}
```

| 字段 | 说明 |
| --- | --- |
| `report_interval` | 面板为该服务器设置的更新频率（秒），仅作推荐 |
| `next_report_at` | 按上述频率推算的下次上报时间（ISO8601，UTC），仅作推荐 |

两个推荐值都只是建议：上报端可以按自己的节奏上报，面板不做任何频率校验，也不会因为提前或延后上报而拒收。

失败：

```json
{
  "ok": false,
  "error": {
    "code": "invalid_api_key",
    "message": "API KEY 无效"
  }
}
```

| HTTP | code | 含义 |
| --- | --- | --- |
| 201 | — | 上报成功 |
| 400 | `invalid_json` / `empty_body` | 请求体为空或不是合法 JSON |
| 401 | `missing_api_key` | 未提供 API KEY |
| 401 | `invalid_api_key` | API KEY 不存在或已失效 |
| 413 | `payload_too_large` | 超过 256 KB |
| 422 | `empty_report` | 没有任何可识别的监控字段 |
| 500 | `internal_error` | 服务端异常 |
| 503 | `not_installed` | 面板尚未完成安装 |

---

## 3. 自检接口

用于确认 KEY 是否正确，并取回推荐的上报频率。

```
GET /api/v1/server
Authorization: Bearer <API_KEY>
```

```json
{
  "ok": true,
  "server": {
    "id": 1,
    "name": "web-01",
    "provider": "阿里云",
    "location": "香港",
    "last_seen_at": "2026-05-20 06:32:11"
  },
  "report_endpoint": "/api/v1/report",
  "report_interval": 60,
  "accepted_fields": {
    "system": ["timezone", "server_time", "hostname", "os", "kernel", "arch", "uptime"],
    "cpu": ["model", "cores", "usage"],
    "memory": ["total", "used"],
    "swap": ["total", "used"],
    "disk": ["total", "used"],
    "network": ["up", "down"]
  },
  "computed_by_server": ["memory.usage", "swap.usage", "disk.usage", "disk.free", "load.1", "load.5", "load.15"]
}
```

`report_interval` 是面板为该服务器设置的更新频率（秒），上报程序可据此决定自己的上报间隔。该值仅作推荐，不做强制校验。

---

## 4. 健康检查

无需认证，用于探活。

```
GET /api/v1/health
```

```json
{ "ok": true, "service": "Pulse", "version": "1.0.0", "time": "2026-05-20T06:32:11+00:00" }
```
