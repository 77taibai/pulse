<?php
/** @var string $content */
/** @var string $title */
use App\Support\Url;
use App\Support\View;

$title = $title ?? ($appName ?? 'Pulse');

// 当前路径（去掉 base_path），用于高亮导航
$currentPath = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'), '/');
$base = Url::basePath();
if ($base !== '' && str_starts_with($currentPath, $base)) {
    $currentPath = substr($currentPath, strlen($base)) ?: '/';
}
$script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
if (str_starts_with($currentPath, '/' . $script)) {
    $currentPath = substr($currentPath, strlen($script) + 1) ?: '/';
}
$currentPath = '/' . trim($currentPath, '/');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= View::asset('favicon.svg') ?>">
<link rel="stylesheet" href="<?= View::asset('app.css') ?>">
</head>
<body class="app">
<header class="topbar">
  <a class="brand" href="<?= View::url('') ?>">
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 12h4l3 8 4-16 3 8h6"/>
      </svg>
    </span>
    <span class="brand-text"><?= View::e($appName ?? 'Pulse') ?><em>服务器监控</em></span>
  </a>

  <nav class="topnav">
    <a href="<?= View::url('') ?>" class="<?= $currentPath === '/' ? 'active' : '' ?>">总览</a>
    <a href="<?= View::url('servers/create') ?>" class="<?= str_starts_with($currentPath, '/servers/create') ? 'active' : '' ?>">添加服务器</a>
    <a href="<?= View::url('api-docs') ?>" class="<?= str_starts_with($currentPath, '/api-docs') ? 'active' : '' ?>">API 文档</a>
  </nav>

  <div class="topbar-right">
    <?php if (!empty($_SESSION['auth'])): ?>
      <form method="post" action="<?= View::url('logout') ?>" class="inline">
        <button class="btn btn-ghost btn-sm" type="submit">退出</button>
      </form>
    <?php endif; ?>
  </div>
</header>

<main class="container">
  <?php foreach (($flashes ?? []) as $flash): ?>
    <div class="alert alert-<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>

<footer class="footer">
  <span><?= View::e($appName ?? 'Pulse') ?> · 轻量服务器状态监控</span>
</footer>

<script src="<?= View::asset('app.js') ?>"></script>
</body>
</html>
