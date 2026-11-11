<?php
use App\Support\Format;
use App\Support\View;

/** @var array $servers */
/** @var array $stats */
?>
<div class="page-head">
  <div>
    <h1>服务器总览</h1>
    <p class="muted">共 <?= (int) $stats['total'] ?> 台 · 在线 <?= (int) $stats['online'] ?> · 离线 <?= (int) $stats['offline'] ?></p>
  </div>
  <a class="btn btn-primary" href="<?= View::url('servers/create') ?>">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    添加服务器
  </a>
</div>

<?php if ($servers === []): ?>
  <div class="card empty">
    <div class="empty-icon">🛰️</div>
    <h2>还没有服务器</h2>
    <p class="muted">创建一台服务器，拿到 API KEY 后即可开始接收状态上报。</p>
    <a class="btn btn-primary" href="<?= View::url('servers/create') ?>">添加第一台服务器</a>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($servers as $s): ?>
      <?php
        $online = (bool) $s['online'];
        $cpu = $s['cpu_usage'] !== null ? (float) $s['cpu_usage'] : null;
        $mem = $s['mem_usage'] !== null ? (float) $s['mem_usage'] : null;
        $disk = $s['disk_usage'] !== null ? (float) $s['disk_usage'] : null;
      ?>
      <a class="card server-card <?= $online ? 'is-online' : 'is-offline' ?>" href="<?= View::url('servers/' . (int) $s['id']) ?>">
        <div class="server-card-head">
          <div class="server-title">
            <h2><?= View::e($s['name']) ?></h2>
            <p class="muted small">
              <?= View::e($s['provider'] ?: '未填写服务商') ?>
              <?php if (!empty($s['location'])): ?> · <?= View::e($s['location']) ?><?php endif; ?>
            </p>
          </div>
          <span class="status <?= $online ? 'on' : 'off' ?>">
            <i></i><?= $online ? '在线' : '离线' ?>
          </span>
        </div>

        <?php if ($s['r_received_at'] === null): ?>
          <p class="muted small no-data">尚未收到任何上报</p>
        <?php else: ?>
          <div class="metrics">
            <div class="metric">
              <span class="metric-label">CPU</span>
              <span class="metric-value"><?= Format::percent($cpu) ?></span>
              <div class="bar"><i style="width:<?= (float) ($cpu ?? 0) ?>%"></i></div>
            </div>
            <div class="metric">
              <span class="metric-label">内存</span>
              <span class="metric-value"><?= Format::percent($mem) ?></span>
              <div class="bar"><i style="width:<?= (float) ($mem ?? 0) ?>%"></i></div>
            </div>
            <div class="metric">
              <span class="metric-label">硬盘</span>
              <span class="metric-value"><?= Format::percent($disk) ?></span>
              <div class="bar"><i style="width:<?= (float) ($disk ?? 0) ?>%"></i></div>
            </div>
          </div>

          <div class="server-card-foot">
            <span class="net">
              <?php if ($s['net_up'] === null && $s['net_down'] === null): ?>
                <span class="muted">未上报网络速率</span>
              <?php else: ?>
                <em>↑</em><?= Format::speed($s['net_up'] !== null ? (int) $s['net_up'] : null) ?>
                <em>↓</em><?= Format::speed($s['net_down'] !== null ? (int) $s['net_down'] : null) ?>
              <?php endif; ?>
            </span>
            <span class="small <?= $online ? 'muted' : 'stale' ?>"><?= View::e(Format::ago($s['r_received_at'])) ?></span>
          </div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
