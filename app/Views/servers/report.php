<?php
use App\Support\Format;
use App\Support\View;

/** @var array $server */
/** @var array $report */
?>
<div class="page-head">
  <div class="page-head-main">
    <a class="back" href="<?= View::url('servers/' . (int) $server['id'] . '/history') ?>" aria-label="返回">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div>
      <h1>上报 #<?= (int) $report['id'] ?></h1>
      <p class="muted"><?= View::e($server['name']) ?> · <?= Format::datetime($report['received_at']) ?></p>
    </div>
  </div>
</div>

<div class="card">
  <h2 class="card-title">解析结果</h2>
  <dl class="kv">
    <dt>服务器时间</dt><dd><?= View::e($report['server_time_raw'] ?: '—') ?> <span class="muted small">(<?= View::e($report['timezone'] ?: '未上报时区') ?>)</span></dd>
    <dt>时钟偏差</dt><dd><?= View::e(Format::skew($report['clock_skew'] !== null ? (int) $report['clock_skew'] : null)) ?></dd>
    <dt>CPU</dt><dd><?= View::e($report['cpu_model'] ?: '—') ?> · <?= $report['cpu_cores'] !== null ? (int) $report['cpu_cores'] . ' 核' : '核心数未知' ?> · <?= Format::percent($report['cpu_usage'] !== null ? (float) $report['cpu_usage'] : null) ?></dd>
    <dt>内存</dt><dd><?= Format::bytes($report['mem_used'] !== null ? (int) $report['mem_used'] : null) ?> / <?= Format::bytes($report['mem_total'] !== null ? (int) $report['mem_total'] : null) ?> · <?= Format::percent($report['mem_usage'] !== null ? (float) $report['mem_usage'] : null) ?></dd>
    <dt>虚拟内存</dt><dd><?= Format::bytes($report['swap_used'] !== null ? (int) $report['swap_used'] : null) ?> / <?= Format::bytes($report['swap_total'] !== null ? (int) $report['swap_total'] : null) ?> · <?= Format::percent($report['swap_usage'] !== null ? (float) $report['swap_usage'] : null) ?></dd>
    <dt>硬盘</dt><dd>总计 <?= Format::bytes($report['disk_total'] !== null ? (int) $report['disk_total'] : null) ?> · 已用 <?= Format::bytes($report['disk_used'] !== null ? (int) $report['disk_used'] : null) ?> · 剩余 <?= Format::bytes($report['disk_free'] !== null ? (int) $report['disk_free'] : null) ?> · <?= Format::percent($report['disk_usage'] !== null ? (float) $report['disk_usage'] : null) ?></dd>
    <dt>网络</dt><dd>↑ <?= Format::speed($report['net_up'] !== null ? (int) $report['net_up'] : null) ?> · ↓ <?= Format::speed($report['net_down'] !== null ? (int) $report['net_down'] : null) ?></dd>
    <dt>负载 (1/5/15 分钟)</dt><dd><?= $report['load1'] !== null ? View::e($report['load1'] . ' / ' . $report['load5'] . ' / ' . $report['load15']) : '—' ?></dd>
    <dt>运行时长</dt><dd><?= View::e(Format::uptime($report['uptime'] !== null ? (int) $report['uptime'] : null)) ?></dd>
    <dt>主机 / 系统</dt><dd><?= View::e($report['hostname'] ?: '—') ?> · <?= View::e($report['os'] ?: '—') ?> <?= View::e($report['kernel'] ?? '') ?></dd>
    <dt>来源 IP</dt><dd><?= View::e($report['ip'] ?: '—') ?></dd>
  </dl>
</div>

<div class="card">
  <h2 class="card-title">原始上报数据</h2>
  <pre class="raw"><?= View::e($raw ?: '（未保存原始数据）') ?></pre>
</div>
