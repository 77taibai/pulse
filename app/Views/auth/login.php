<?php
use App\Support\View;
?>
<section class="auth-wrap">
  <div class="card auth-card">
    <div class="auth-head">
      <div class="brand-mark lg" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2 12h4l3 8 4-16 3 8h6"/>
        </svg>
      </div>
      <h1><?= View::e($appName) ?></h1>
      <p class="muted">登录以查看服务器状态</p>
    </div>

    <form method="post" action="<?= View::url('login') ?>" class="stack">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">

      <label class="field">
        <span>用户名</span>
        <input type="text" name="username" value="<?= View::e($username) ?>" autocomplete="username" required autofocus>
      </label>

      <label class="field">
        <span>密码</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>

      <button class="btn btn-primary btn-block" type="submit">登录</button>
    </form>
  </div>
</section>
