<?php
/**
 * Qndocs - 后台登录
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_install();

if (is_logged_in()) {
    redirect(admin_url('index.php'));
}

$error    = '';
$username = '';
$redirect = (string) ($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $username = trim((string) post('username'));
    $redirect = (string) post('redirect');
    [$ok, $message] = qn_login($username, (string) post('password'));
    if ($ok) {
        $target = admin_url('index.php');
        if ($redirect !== '' && strpos($redirect, '//') === false && strpos($redirect, 'http') === false) {
            $target = $redirect;
        }
        redirect($target);
    }
    $error = $message;
}

$site = (string) opt('site_name', 'Qndocs');
$vars = qn_css_vars();
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>登录 · <?= e($site) ?> 后台</title>
<?php if (!qn_plain_mode()): ?>
<link rel="stylesheet" href="<?= e(qn_asset('admin.css')) ?>">
<style>:root{
  --primary: <?= e($vars['primary'] ?? '#2563eb') ?>;
  --primary-dark: <?= e($vars['primary-dark'] ?? '#1d4ed8') ?>;
  --primary-soft: <?= e($vars['primary-soft'] ?? '#e8f0fe') ?>;
}</style>
<?php endif; ?>
</head>
<body class="ad ad-login-page">
<div class="ad-login">
  <div class="ad-login-brand">
    <span class="ad-logo-mark lg">QN</span>
    <h1><?= e($site) ?></h1>
    <p>内容后台 · 请登录后继续</p>
  </div>

  <?php if ($error !== ''): ?>
    <div class="ad-alert err"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= e(admin_url('login.php')) ?>" class="ad-login-form">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <label>
      <span>管理员账号</span>
      <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" autofocus required>
    </label>
    <label>
      <span>登录密码</span>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="ad-btn block" type="submit">登 录</button>
  </form>

  <div class="ad-login-foot">
    <a href="<?= e(qn_url('')) ?>">← 返回前台</a>
    <span>Qndocs v<?= e(QN_VERSION) ?></span>
  </div>
</div>
</body>
</html>
