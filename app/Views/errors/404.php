<?php
use App\Support\View;
?>
<div class="card empty">
  <div class="empty-icon">404</div>
  <h1><?= View::e($message ?? '页面不存在') ?></h1>
  <p class="muted">请检查地址是否正确。</p>
  <a class="btn btn-primary" href="<?= View::url('') ?>">返回总览</a>
</div>
