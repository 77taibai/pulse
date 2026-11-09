<?php
/** @var string $content */
/** @var string $title */
use App\Support\View;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Pulse') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= View::asset('favicon.svg') ?>">
<link rel="stylesheet" href="<?= View::asset('app.css') ?>">
</head>
<body class="app centered">
<main class="container narrow">
  <?php foreach (($flashes ?? []) as $flash): ?>
    <div class="alert alert-<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<script src="<?= View::asset('app.js') ?>"></script>
</body>
</html>
