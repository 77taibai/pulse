<?php
use App\Support\View;
?>
<div class="card empty">
  <div class="empty-icon">500</div>
  <h1>服务器内部错误</h1>
  <p class="muted"><?= View::e($message ?? '请稍后再试') ?></p>
  <?php if (!empty($trace)): ?>
    <pre class="raw"><?= View::e($trace) ?></pre>
  <?php endif; ?>
  <a class="btn btn-primary" href="<?= View::url('') ?>">返回总览</a>
</div>
