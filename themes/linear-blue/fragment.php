<?php
/**
 * 片段模式渲染：只输出「样式 + 文档正文」，不带 html/head/body
 * 适合把文档内容直接嵌进自己的软件界面里。
 *
 * @var array $ctx
 */
?>
<style><?= $ctx['css'] ?></style>
<article class="qn-doc" id="qnDoc"><?= $ctx['body'] ?></article>
<?= $ctx['foot_code'] ?? '' ?>
