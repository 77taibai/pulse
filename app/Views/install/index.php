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

    <h2 class="card-title" style="margin-top:8px">OIDC 单点登录（可选）</h2>
    <label class="checkbox-field">
      <input type="checkbox" name="oidc_enabled" id="oidc-enabled" value="1" <?= !empty($old['oidc_enabled']) ? 'checked' : '' ?>>
      <span>启用 OIDC 登录</span>
    </label>

    <div id="oidc-fields">
      <label class="field">
        <span>Issuer</span>
        <input type="url" name="oidc_issuer" value="<?= View::e($old['oidc_issuer'] ?? '') ?>" placeholder="https://id.example.com">
        <span class="field-hint">程序会读取 {issuer}/.well-known/openid-configuration</span>
      </label>

      <div class="field-row">
        <label class="field">
          <span>Client ID</span>
          <input type="text" name="oidc_client_id" value="<?= View::e($old['oidc_client_id'] ?? '') ?>" autocomplete="off">
        </label>
        <label class="field">
          <span>Client Secret</span>
          <input type="password" name="oidc_client_secret" value="" autocomplete="new-password">
          <span class="field-hint">公有客户端（仅 PKCE）可留空</span>
        </label>
      </div>

      <label class="field">
        <span>Scopes</span>
        <input type="text" name="oidc_scopes" value="<?= View::e($old['oidc_scopes'] ?? 'openid profile email') ?>">
      </label>

      <label class="field">
        <span>允许登录的 sub <b class="req">*</b></span>
        <textarea name="oidc_allowed_subs" rows="3" placeholder="每行一个 sub，例如：&#10;248289761001&#10;auth0|5f8e..."><?= View::e($old['oidc_allowed_subs'] ?? '') ?></textarea>
        <span class="field-hint">只有 sub 在名单内的账号才能通过 OIDC 登录</span>
      </label>

      <label class="field">
        <span>回调地址 (Redirect URI)</span>
        <input type="url" name="oidc_redirect_uri" value="<?= View::e($old['oidc_redirect_uri'] ?? ($oidcRedirectUri ?? '')) ?>">
        <span class="field-hint">请在身份提供方登记此地址；安装后固定使用该值</span>
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

    <h2 class="card-title" style="margin-top:8px">数据保留</h2>
    <div class="field-row">
      <label class="field">
        <span>保留天数</span>
        <input type="number" name="retention_days" min="0" step="1" value="<?= View::e((string) ($old['retention_days'] ?? 30)) ?>">
        <span class="muted small">0 表示不按时间清理</span>
      </label>
      <label class="field">
        <span>数据库上限 (MB)</span>
        <input type="number" name="max_db_mb" min="0" step="1" value="<?= View::e((string) ($old['max_db_mb'] ?? 500)) ?>">
        <span class="muted small">超出后自动删除最旧记录，0 表示不限制</span>
      </label>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary btn-block" type="submit" <?= $allOk ? '' : 'disabled' ?>>
        <?= $allOk ? '开始安装' : '请先解决环境问题' ?>
      </button>
    </div>
  </form>
</div>
