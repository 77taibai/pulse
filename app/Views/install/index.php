<?php
use App\Support\View;

/** @var array $checks */
/** @var string $csrf */
/** @var string $basePath */
/** @var string $origin */
$allOk = true;
foreach ($checks as $check) {
    if (!$check['ok']) {
        $allOk = false;
        break;
    }
}
?>
<div class="install-wrap">
  <div class="install-head">
    <div class="brand-mark lg" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 12h4l3 8 4-16 3 8h6"/>
      </svg>
    </div>
    <h1>安装 <?= View::e($appName ?? 'Pulse') ?></h1>
    <p class="muted">配置数据库与管理员账号，全部在浏览器内完成</p>
  </div>

  <?php foreach (($flashes ?? []) as $flash): ?>
    <div class="alert alert-<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
  <?php endforeach; ?>

  <div class="card install-card">
    <h2 class="card-title">环境检查</h2>
    <ul class="check-list">
      <?php foreach ($checks as $check): ?>
        <li class="<?= $check['ok'] ? 'ok' : 'bad' ?>">
          <span class="check-icon"><?= $check['ok'] ? '✓' : '!' ?></span>
          <span class="check-label"><?= View::e($check['label']) ?></span>
          <span class="check-value"><?= View::e($check['value']) ?></span>
          <?php if (!$check['ok']): ?>
            <span class="check-hint"><?= View::e($check['hint']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" action="<?= View::e($formAction) ?>" class="card install-card" id="install-form" data-probe="<?= View::e($probeUrl) ?>">
    <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
    <!-- 由浏览器实测伪静态是否可用：1=可用 0=不可用 -->
    <input type="hidden" name="rewrite_ok" id="rewrite-ok" value="0">

    <h2 class="card-title">数据库</h2>
    <div class="field-row">
      <label class="field" style="flex:2 1 200px">
        <span>主机 <b class="req">*</b></span>
        <input type="text" name="db_host" value="<?= View::e($old['db_host'] ?? 'localhost') ?>" required>
      </label>
      <label class="field" style="flex:1 1 100px">
        <span>端口</span>
        <input type="number" name="db_port" value="<?= View::e($old['db_port'] ?? '3306') ?>">
      </label>
    </div>

    <label class="field">
      <span>数据库名 <b class="req">*</b></span>
      <input type="text" name="db_name" value="<?= View::e($old['db_name'] ?? '') ?>" required placeholder="例如：pulse">
    </label>

    <div class="field-row">
      <label class="field">
        <span>用户名 <b class="req">*</b></span>
        <input type="text" name="db_user" value="<?= View::e($old['db_user'] ?? '') ?>" required>
      </label>
      <label class="field">
        <span>密码</span>
        <input type="password" name="db_pass" value="" autocomplete="new-password">
      </label>
    </div>

    <h2 class="card-title" style="margin-top:8px">管理员账号</h2>
    <label class="field">
      <span>用户名 <b class="req">*</b></span>
      <input type="text" name="admin_user" value="<?= View::e($old['admin_user'] ?? 'admin') ?>" required>
    </label>

    <div class="field-row">
      <label class="field">
        <span>密码 <b class="req">*</b></span>
        <input type="password" name="admin_pass" required autocomplete="new-password" minlength="6">
      </label>
      <label class="field">
        <span>确认密码 <b class="req">*</b></span>
        <input type="password" name="admin_pass2" required autocomplete="new-password" minlength="6">
      </label>
    </div>

    <label class="field">
      <span>面板时区</span>
      <select name="timezone">
        <?php foreach (['Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'Asia/Tokyo', 'Asia/Singapore', 'UTC', 'Europe/London', 'America/New_York'] as $tz): ?>
          <option value="<?= View::e($tz) ?>" <?= ($old['timezone'] ?? 'Asia/Shanghai') === $tz ? 'selected' : '' ?>><?= View::e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="form-actions">
      <button class="btn btn-primary btn-block" type="submit" <?= $allOk ? '' : 'disabled' ?>>
        <?= $allOk ? '开始安装' : '请先解决环境问题' ?>
      </button>
    </div>
  </form>
</div>
