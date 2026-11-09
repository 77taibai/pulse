<?php
use App\Support\View;
?>
<div class="page-head">
  <div>
    <h1>添加服务器</h1>
    <p class="muted">创建后系统会生成一个 API KEY，供服务器端上报程序调用。KEY 只显示一次，请当场复制保存。</p>
  </div>
  <a class="btn btn-ghost" href="<?= View::url('') ?>">返回总览</a>
</div>

<div class="card form-card">
  <form method="post" action="<?= View::url('servers') ?>" class="stack">
    <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">

    <label class="field">
      <span>服务器名称 <b class="req">*</b></span>
      <input type="text" name="name" maxlength="100" required autofocus placeholder="例如：web-01">
    </label>

    <div class="field-row">
      <label class="field">
        <span>服务商</span>
        <input type="text" name="provider" maxlength="100" placeholder="例如：阿里云 / Vultr">
      </label>

      <label class="field">
        <span>位置</span>
        <input type="text" name="location" maxlength="100" placeholder="例如：香港 / 东京">
      </label>
    </div>

    <label class="field">
      <span>备注</span>
      <input type="text" name="note" maxlength="255" placeholder="选填，例如：生产环境主站">
    </label>

    <div class="field">
      <label for="report-interval">更新频率</label>
      <select id="report-interval" name="report_interval">
        <?php foreach ($intervals as $seconds => $label): ?>
          <option value="<?= (int) $seconds ?>" <?= (int) $interval === (int) $seconds ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">创建并生成 API KEY</button>
      <a class="btn btn-ghost" href="<?= View::url('') ?>">取消</a>
    </div>
  </form>
</div>
