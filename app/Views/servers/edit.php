<?php
use App\Support\Format;
use App\Support\View;

/** @var array $server */
?>
<div class="page-head">
  <div>
    <h1>编辑服务器</h1>
    <p class="muted"><?= View::e($server['name']) ?> · 创建于 <?= Format::datetime($server['created_at']) ?></p>
  </div>
  <a class="btn btn-ghost" href="<?= View::url('servers/' . (int) $server['id']) ?>">返回详情</a>
</div>

<div class="page-stack">
  <div class="card">
    <form method="post" action="<?= View::url('servers/' . (int) $server['id']) ?>" class="stack">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">

      <label class="field">
        <span>服务器名称 <b class="req">*</b></span>
        <input type="text" name="name" maxlength="100" required value="<?= View::e($server['name']) ?>">
      </label>

      <div class="field-row">
        <label class="field">
          <span>服务商</span>
          <input type="text" name="provider" maxlength="100" value="<?= View::e($server['provider']) ?>" placeholder="例如：阿里云 / Vultr">
        </label>
        <label class="field">
          <span>位置</span>
          <input type="text" name="location" maxlength="100" value="<?= View::e($server['location']) ?>" placeholder="例如：香港 / 东京">
        </label>
      </div>

      <label class="field">
        <span>备注</span>
        <input type="text" name="note" maxlength="255" value="<?= View::e($server['note']) ?>">
      </label>

      <div class="field">
        <label for="report-interval">更新频率</label>
        <select id="report-interval" name="report_interval">
          <?php foreach ($intervals as $seconds => $label): ?>
            <option value="<?= (int) $seconds ?>" <?= (int) $interval === (int) $seconds ? 'selected' : '' ?>><?= View::e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted small field-hint">建议上报程序按此频率上报，仅作推荐，不做强制要求。</span>
      </div>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit">保存修改</button>
        <a class="btn btn-ghost" href="<?= View::url('servers/' . (int) $server['id']) ?>">取消</a>
      </div>
    </form>
  </div>
</div>
