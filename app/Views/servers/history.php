<?php
use App\Support\Format;
use App\Support\View;

/** @var array $server */
/** @var array $history */
?>
<div class="page-head">
  <div class="page-head-main">
    <a class="back" href="<?= View::url('servers/' . (int) $server['id']) ?>" aria-label="返回">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div>
      <h1>上报记录</h1>
      <p class="muted"><?= View::e($server['name']) ?> · 共 <?= (int) $history['total'] ?> 条</p>
    </div>
  </div>
</div>

<div class="card table-card">
  <?php if ($history['rows'] === []): ?>
    <p class="muted">暂无记录。</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>接收时间</th>
          <th>CPU</th>
          <th>内存</th>
          <th>硬盘</th>
          <th>上行</th>
          <th>下行</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($history['rows'] as $row): ?>
        <tr>
          <td class="mono"><?= (int) $row['id'] ?></td>
          <td><?= Format::datetime($row['received_at']) ?></td>
          <td><?= Format::percent($row['cpu_usage'] !== null ? (float) $row['cpu_usage'] : null) ?></td>
          <td><?= Format::percent($row['mem_usage'] !== null ? (float) $row['mem_usage'] : null) ?></td>
          <td><?= Format::percent($row['disk_usage'] !== null ? (float) $row['disk_usage'] : null) ?></td>
          <td><?= Format::speed($row['net_up'] !== null ? (int) $row['net_up'] : null) ?></td>
          <td><?= Format::speed($row['net_down'] !== null ? (int) $row['net_down'] : null) ?></td>
          <td><a class="link" href="<?= View::url('servers/' . (int) $server['id'] . '/reports/' . (int) $row['id']) ?>">详情</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($history['pages'] > 1): ?>
      <nav class="pager">
        <?php
          $current = (int) $history['page'];
          $pages = (int) $history['pages'];
          $window = 2;
          $start = max(1, $current - $window);
          $end = min($pages, $current + $window);
        ?>
        <?php if ($current > 1): ?>
          <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/history?page=' . ($current - 1)) ?>">上一页</a>
        <?php endif; ?>
        <?php if ($start > 1): ?>
          <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/history?page=1') ?>">1</a>
          <?php if ($start > 2): ?><span class="muted">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
          <a class="btn btn-sm <?= $i === $current ? 'btn-primary' : 'btn-ghost' ?>"
             href="<?= View::url('servers/' . (int) $server['id'] . '/history?page=' . $i) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
          <?php if ($end < $pages - 1): ?><span class="muted">…</span><?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/history?page=' . $pages) ?>"><?= $pages ?></a>
        <?php endif; ?>
        <?php if ($current < $pages): ?>
          <a class="btn btn-ghost btn-sm" href="<?= View::url('servers/' . (int) $server['id'] . '/history?page=' . ($current + 1)) ?>">下一页</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
