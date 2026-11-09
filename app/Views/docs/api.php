<?php
use App\Support\View;
?>
<div class="card docs">
  <div class="docs-head">
    <div class="docs-head-main">
      <h1>API 文档</h1>
      <p class="muted">服务器端上报程序对接说明</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= View::e($download) ?>" download>
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 11l5 5 5-5M4 20h16"/></svg>
      下载 .md
    </a>
  </div>
  <article class="md">
    <?= $html ?>
  </article>
</div>
