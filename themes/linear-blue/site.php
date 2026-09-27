<?php
/**
 * 整合文档站模板：页头 + 侧边文档树 + 正文 + 右栏目录 + 页脚
 * 由后台「站点设置 → 输出模式」选择启用；独立文档模式用 doc.php。
 *
 * @var array $ctx
 */
$site = $ctx['site'];
$slug = !empty($ctx['doc']) ? (string) $ctx['doc']['slug'] : '';
$foot = $site['footer'] !== '' ? $site['footer'] : '© {year} {site}';
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
<body class="qn-site">

<header class="qn-site-header">
  <div class="qn-site-header-in">
    <button class="qn-site-menu" id="qnSiteMenu" type="button" aria-label="打开目录">☰</button>
    <a class="qn-site-brand" href="<?= e(qn_url('')) ?>">
      <span class="qn-brand-mark"><?= e(mb_substr($site['name'], 0, 2, 'UTF-8')) ?></span>
      <strong><?= e($site['name']) ?></strong>
    </a>
    <nav class="qn-site-tnav"><?= qn_top_nav_html($slug) ?></nav>
  </div>
</header>

<div class="qn-site-shell">
  <?php if (!empty($ctx['nav'])): ?>
    <aside class="qn-site-side" id="qnSiteSide">
      <div class="qn-site-side-hd">文档目录</div>
      <?= qn_nav_html($ctx['nav'], $slug) ?>
    </aside>
  <?php endif; ?>

  <main class="qn-site-main">
    <?php if (!empty($ctx['breadcrumb'])): ?>
      <nav class="qn-site-crumb">
        <a href="<?= e(qn_url('')) ?>">首页</a>
        <?php foreach ($ctx['breadcrumb'] as $item): ?>
          <i>/</i><a href="<?= e(doc_url($item)) ?>"><?= e($item['title']) ?></a>
        <?php endforeach; ?>
        <?php if (!empty($ctx['doc'])): ?><i>/</i><span><?= e($ctx['doc']['title']) ?></span><?php endif; ?>
      </nav>
    <?php endif; ?>

    <article class="qn-doc qn-site-doc" id="qnDoc"><?= $ctx['body'] ?></article>

    <?php
    $prev = isset($ctx['siblings']['prev']) ? $ctx['siblings']['prev'] : null;
    $next = isset($ctx['siblings']['next']) ? $ctx['siblings']['next'] : null;
    if ($prev || $next):
      ?>
      <nav class="qn-site-prevnext">
        <?php if ($prev): ?>
          <a class="qn-site-pn" href="<?= e(doc_url($prev)) ?>"><em>← 上一篇</em><span><?= e($prev['title']) ?></span></a>
        <?php else: ?><span class="qn-site-pn empty"></span><?php endif; ?>
        <?php if ($next): ?>
          <a class="qn-site-pn next" href="<?= e(doc_url($next)) ?>"><em>下一篇 →</em><span><?= e($next['title']) ?></span></a>
        <?php else: ?><span class="qn-site-pn empty"></span><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>

  <aside class="qn-site-toc" id="qnSiteToc"></aside>
</div>

<footer class="qn-site-footer">
  <div class="qn-site-footer-in">
    <span><?= e(str_replace(['{year}', '{site}'], [date('Y'), $site['name']], $foot)) ?></span>
    <span>
      <a href="<?= e(qn_url('')) ?>">首页</a> ·
      <a href="<?= e(qn_url('index.php?p=__search')) ?>">搜索</a> ·
      <a href="<?= e(qn_url('index.php?p=__sitemap')) ?>">站点地图</a>
    </span>
  </div>
</footer>

<?php if (!empty($ctx['hit_id'])): ?>
<span id="qnHit" hidden data-url="<?= e(qn_url('index.php?p=__hit&id=' . (int) $ctx['hit_id'])) ?>"></span>
<?php endif; ?>
<?= $ctx['foot_code'] ?? '' ?>

<script>
(function () {
  var content = document.getElementById('qnDoc');
  var toc = document.getElementById('qnSiteToc');
  if (content && toc) {
    var heads = content.querySelectorAll('h2, h3');
    if (heads.length >= 2) {
      var wrap = document.createElement('div');
      wrap.className = 'qn-toc-in';
      var hd = document.createElement('div');
      hd.className = 'qn-toc-hd';
      hd.textContent = '本页目录';
      var ul = document.createElement('ul');
      Array.prototype.forEach.call(heads, function (h, i) {
        if (!h.id) { h.id = 'qn-h-' + i; }
        var li = document.createElement('li');
        li.className = h.tagName === 'H3' ? 'lv3' : 'lv2';
        var a = document.createElement('a');
        a.href = '#' + h.id;
        a.textContent = h.textContent;
        li.appendChild(a);
        ul.appendChild(li);
      });
      wrap.appendChild(hd);
      wrap.appendChild(ul);
      toc.appendChild(wrap);

      var links = ul.querySelectorAll('a');
      if ('IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) {
              Array.prototype.forEach.call(links, function (a) {
                a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id);
              });
            }
          });
        }, { rootMargin: '-72px 0px -70% 0px' });
        Array.prototype.forEach.call(heads, function (h) { obs.observe(h); });
      }
    }
  }

  var btn = document.getElementById('qnSiteMenu');
  if (btn) {
    btn.addEventListener('click', function () { document.body.classList.toggle('qn-side-open'); });
  }

  var hit = document.getElementById('qnHit');
  if (hit && hit.dataset.url) {
    try { fetch(hit.dataset.url, { credentials: 'same-origin', keepalive: true }); } catch (e) {}
  }
})();
</script>
</body>
</html>
