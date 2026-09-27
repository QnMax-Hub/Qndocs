<?php
/**
 * 主题 linear-blue - 独立文档页
 *
 * 只有文档正文本身：没有页头、页脚、侧栏、导航、脚本。
 * 可直接嵌进软件（WebView / iframe）或浏览器单独打开。
 *
 * @var array $ctx
 */
$site = $ctx['site'];
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($ctx['title']) ?></title>
<?php if (!empty($ctx['desc'])): ?>
<meta name="description" content="<?= e($ctx['desc']) ?>">
<?php endif; ?>
<?php if (!empty($ctx['keywords'])): ?>
<meta name="keywords" content="<?= e($ctx['keywords']) ?>">
<?php endif; ?>
<?php if (!empty($ctx['canonical'])): ?>
<link rel="canonical" href="<?= e($ctx['canonical']) ?>">
<?php endif; ?>
<meta name="generator" content="Qndocs <?= e(QN_VERSION) ?>">
<style><?= $ctx['css'] ?></style>
<?= $site['head'] ?>
</head>
<body class="qn-doc-page">
<article class="qn-doc" id="qnDoc"><?= $ctx['body'] ?></article>
<?= $ctx['foot_code'] ?? '' ?>
</body>
</html>
