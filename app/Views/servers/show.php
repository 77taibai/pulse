<?php
use App\Support\Format;
use App\Support\View;

/** @var array $server */
/** @var array|null $latest */
/** @var array $chartData */
/** @var int $reportTotal */
/** @var bool $online */
/** @var int $interval */
// 只有创建 / 重置后的第一次渲染才会带上明文，之后恒为 null
$createdKey = $createdKey ?? null;

$hasData = $latest !== null;
$cpu = $hasData && $latest['cpu_usage'] !== null ? (float) $latest['cpu_usage'] : null;
$mem = $hasData && $latest['mem_usage'] !== null ? (float) $latest['mem_usage'] : null;
$swap = $hasData && $latest['swap_usage'] !== null ? (float) $latest['swap_usage'] : null;
$disk = $hasData && $latest['disk_usage'] !== null ? (float) $latest['disk_usage'] : null;

// 未配置交换分区（或监控端未上报该字段）时不展示 0/0 这种无意义数字
$swapTotal = $hasData && $latest['swap_total'] !== null ? (int) $latest['swap_total'] : null;
$swapDisabled = $swapTotal === null || $swapTotal === 0;
?>

<?php if ($createdKey !== null): ?>
  <div class="card key-card key-card-hero">
    <div class="key-hero-head">
      <div class="key-hero-title">
        <span class="badge badge-warn">仅显示这一次</span>
        <h2>API KEY</h2>
      </div>
      <p class="key-hero-tip">
        下面这条就是该服务器的<strong>完整 API KEY</strong>，服务器端上报程序需要带上它做身份认证。
        离开或刷新本页后就<strong>再也无法查看</strong>，请立刻复制保存到上报程序配置里。
      </p>
    </div>

    <div class="key-box key-box-hero">
      <code id="server-key"><?= View::e($createdKey) ?></code>
      <button class="btn btn-primary" type="button" data-copy="#server-key">复制 KEY</button>
    </div>

    <p class="key-hero-foot">
      上报程序的对接说明见 <a class="link" href="<?= View::url('api-docs') ?>">API 文档</a>
    </p>
  </div>
<?php endif; ?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back" href="<?= View::url('') ?>" aria-label="返回">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div>
      <h1 class="page-title">
        <span class="page-title-text"><?= View::e($server['name']) ?></span>
        <span class="status <?= $online ? 'on' : 'off' ?>"><i></i><?= $online ? '在线' : '离线' ?></span>
      </h1>
      <p class="muted">
        <?= View::e($server['provider'] ?: '未填写服务商') ?>
        <?php if (!empty($server['location'])): ?> · <?= View::e($server['location']) ?><?php endif; ?>
        · 更新频率 <?= View::e(Format::interval($interval)) ?>
        · 最近上报 <?= View::e(Format::ago($server['last_seen_at'])) ?>
      </p>
    </div>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/history') ?>">上报记录 (<?= (int) $reportTotal ?>)</a>
    <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/edit') ?>">编辑</a>
    <form method="post" action="<?= View::url('servers/' . (int) $server['id'] . '/rotate') ?>" class="inline" data-confirm="重新生成后旧 KEY 立即失效，确定继续？" data-confirm-title="重置 API KEY" data-confirm-ok="确认重置">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button class="btn btn-ghost btn-sm" type="submit">重置 KEY</button>
    </form>
    <form method="post" action="<?= View::url('servers/' . (int) $server['id'] . '/delete') ?>" class="inline" data-confirm="将删除该服务器及其全部历史数据，此操作不可恢复，确定继续？" data-confirm-title="删除服务器" data-confirm-ok="确认删除">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button class="btn btn-danger btn-sm" type="submit">删除</button>
    </form>
  </div>
</div>

<?php if (!$hasData): ?>
  <div class="card empty">
    <div class="empty-icon">📡</div>
    <h2>等待首次上报</h2>
    <p class="muted">在服务器上配置好上报程序后，数据会出现在这里。</p>
  </div>
<?php else: ?>
  <div class="card">
    <h2 class="card-title">系统信息</h2>
    <dl class="kv">
      <dt>主机名</dt><dd><?= View::e($latest['hostname'] ?: '—') ?></dd>
      <dt>操作系统</dt><dd><?= View::e($latest['os'] ?: '—') ?><?= $latest['kernel'] ? ' · ' . View::e($latest['kernel']) : '' ?><?= $latest['arch'] ? ' · ' . View::e($latest['arch']) : '' ?></dd>
      <dt>时区 / 服务器时间</dt>
      <dd>
        <?= View::e($latest['timezone'] ?: '—') ?>
        <?php
          // 把上报时间换算到该服务器自己的时区展示，并标出 UTC 偏移，
          // 避免出现"Asia/Shanghai 却显示 ...Z(UTC)"这种自相矛盾的表述。
          $serverTimeText = Format::serverTime((string) ($latest['server_time_raw'] ?? ''), $latest['timezone'] ?? null);
        ?>
        <?php if ($serverTimeText !== null): ?>
          · <?= View::e($serverTimeText) ?>
        <?php endif; ?>
        <?php if ($latest['clock_skew'] !== null): ?>
          <span class="badge <?= abs((int) $latest['clock_skew']) < 5 ? 'badge-ok' : 'badge-warn' ?>">时钟偏差 <?= View::e(Format::skew($latest['clock_skew'] !== null ? (int) $latest['clock_skew'] : null)) ?></span>
        <?php endif; ?>
      </dd>
      <dt>运行时长</dt><dd><?= View::e(Format::uptime($latest['uptime'] !== null ? (int) $latest['uptime'] : null)) ?></dd>
      <dt>负载 (1/5/15 分钟)</dt><dd><?= $latest['load1'] !== null ? View::e($latest['load1'] . ' / ' . $latest['load5'] . ' / ' . $latest['load15']) : '—' ?></dd>
      <dt>网络速率</dt><dd>↑ <?= Format::speed($latest['net_up'] !== null ? (int) $latest['net_up'] : null) ?> · ↓ <?= Format::speed($latest['net_down'] !== null ? (int) $latest['net_down'] : null) ?></dd>
      <dt>来源 IP</dt><dd><?= View::e($latest['ip'] ?: '—') ?></dd>
      <dt>最近上报</dt><dd><?= Format::datetime($latest['received_at']) ?> <span class="muted small">（<?= View::e(Format::ago($latest['received_at'])) ?>）</span></dd>
    </dl>
  </div>

  <div class="stat-row">
    <div class="stat-card">
      <span class="stat-label">CPU 使用率</span>
      <span class="stat-value" id="stat-cpu"><?= Format::percent($cpu) ?></span>
      <span class="stat-sub muted small"><?= View::e($latest['cpu_model'] ?: '型号未知') ?><?= $latest['cpu_cores'] ? ' · ' . (int) $latest['cpu_cores'] . ' 核' : '' ?></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">内存使用率</span>
      <span class="stat-value" id="stat-mem"><?= Format::percent($mem) ?></span>
      <span class="stat-sub muted small"><?= Format::bytes($latest['mem_used'] !== null ? (int) $latest['mem_used'] : null) ?> / <?= Format::bytes($latest['mem_total'] !== null ? (int) $latest['mem_total'] : null) ?></span>
    </div>
    <div class="stat-card">
      <span class="stat-label">虚拟内存</span>
      <span class="stat-value" id="stat-swap"><?= $swapDisabled ? '未启用' : Format::percent($swap) ?></span>
      <span class="stat-sub muted small">
        <?= $swapDisabled
            ? '未配置 Swap 或未上报'
            : Format::bytes($latest['swap_used'] !== null ? (int) $latest['swap_used'] : null) . ' / ' . Format::bytes($swapTotal) ?>
      </span>
    </div>
    <div class="stat-card">
      <span class="stat-label">硬盘使用率</span>
      <span class="stat-value" id="stat-disk"><?= Format::percent($disk) ?></span>
      <span class="stat-sub muted small">剩余 <?= Format::bytes($latest['disk_free'] !== null ? (int) $latest['disk_free'] : null) ?> / <?= Format::bytes($latest['disk_total'] !== null ? (int) $latest['disk_total'] : null) ?></span>
    </div>
  </div>

  <div class="chart-grid">
    <div class="card chart-card">
      <div class="chart-head">
        <h2>CPU 使用率</h2>
        <span class="chart-legend"><i class="dot cpu"></i>使用率</span>
      </div>
      <div class="chart-body"><canvas id="chart-cpu"></canvas></div>
    </div>

    <div class="card chart-card">
      <div class="chart-head">
        <h2>内存使用率</h2>
        <span class="chart-legend"><i class="dot mem"></i>使用率</span>
      </div>
      <div class="chart-body"><canvas id="chart-mem"></canvas></div>
    </div>

    <div class="card chart-card">
      <div class="chart-head">
        <h2>硬盘使用率</h2>
        <span class="chart-legend"><i class="dot disk"></i>使用率</span>
      </div>
      <div class="chart-body"><canvas id="chart-disk"></canvas></div>
    </div>

    <div class="card chart-card">
      <div class="chart-head">
        <h2>网络流量</h2>
        <span class="chart-legend"><i class="dot up"></i>上行 <i class="dot down"></i>下行</span>
      </div>
      <div class="chart-body"><canvas id="chart-net"></canvas></div>
    </div>
  </div>
<?php endif; ?>

<script>
window.PULSE_CHART = {
  seriesUrl: <?= json_encode(View::url('api/servers/' . (int) $server['id'] . '/series?limit=240'), JSON_UNESCAPED_SLASHES) ?>,
  swapDisabled: <?= $swapDisabled ? 'true' : 'false' ?>,
  data: <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
