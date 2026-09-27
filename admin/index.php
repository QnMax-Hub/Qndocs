<?php
/**
 * Qndocs - 后台仪表盘
 */

require_once __DIR__ . '/_common.php';

$stats = [
    'docs'     => (int) DB::val('SELECT COUNT(*) FROM {docs}'),
    'public'   => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'public'"),
    'draft'    => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'draft'"),
    'private'  => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'private'"),
    'views'    => (int) DB::val('SELECT COALESCE(SUM(views), 0) FROM {docs}'),
    'users'    => (int) DB::val('SELECT COUNT(*) FROM {users}'),
    'revisions'=> (int) DB::val('SELECT COUNT(*) FROM {revisions}'),
    'files'    => (int) DB::val('SELECT COUNT(*) FROM {files}'),
    'size'     => (int) DB::val('SELECT COALESCE(SUM(size), 0) FROM {files}'),
];

$recent  = DB::all('SELECT id, title, slug, status, type, updated_at, views FROM {docs} ORDER BY updated_at DESC LIMIT 8');
$popular = DB::all("SELECT id, title, slug, views FROM {docs} WHERE status = 'public' ORDER BY views DESC LIMIT 5");
$static  = count(glob(QN_DATA . '/pages/*.html') ?: []);
$info    = qn_server_info();
$user    = qn_current_user();

admin_head('仪表盘', 'index.php');
?>

<div class="ad-hero">
  <div>
    <h2>欢迎回来，<?= e($user['nickname'] !== '' ? $user['nickname'] : $user['username']) ?></h2>
    <p>这里是 <?= e((string) opt('site_name', 'Qndocs')) ?> 的内容控制台。文档保存后会自动渲染成 HTML 页面输出到前台。</p>
  </div>
  <div class="ad-hero-actions">
    <a class="ad-btn" href="<?= e(admin_url('editor.php')) ?>"><?= qn_icon('plus') ?>新建文档</a>
    <a class="ad-btn ghost" href="<?= e(admin_url('style.php')) ?>"><?= qn_icon('style') ?>样式中心</a>
  </div>
</div>

<div class="ad-stats">
  <div class="ad-stat">
    <span class="ad-stat-label">文档总数</span>
    <strong><?= $stats['docs'] ?></strong>
    <span class="ad-stat-sub">公开 <?= $stats['public'] ?> · 草稿 <?= $stats['draft'] ?></span>
  </div>
  <div class="ad-stat">
    <span class="ad-stat-label">累计浏览</span>
    <strong><?= number_format($stats['views']) ?></strong>
    <span class="ad-stat-sub">私有文档 <?= $stats['private'] ?> 篇</span>
  </div>
  <div class="ad-stat">
    <span class="ad-stat-label">静态页面</span>
    <strong><?= $static ?></strong>
    <span class="ad-stat-sub">缓存目录 data/pages</span>
  </div>
  <div class="ad-stat">
    <span class="ad-stat-label">媒体文件</span>
    <strong><?= $stats['files'] ?></strong>
    <span class="ad-stat-sub">共 <?= e(qn_bytes($stats['size'])) ?></span>
  </div>
</div>

<div class="ad-grid-2">
  <?php ad_card_open('最近修改'); ?>
    <table class="ad-table">
      <thead><tr><th>标题</th><th>状态</th><th>浏览</th><th>更新时间</th><th></th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="5" class="ad-empty">还没有文档，<a href="<?= e(admin_url('editor.php')) ?>">马上新建一篇</a>。</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $row): ?>
        <tr>
          <td class="ad-cell-main">
            <a href="<?= e(admin_url('editor.php?id=' . (int) $row['id'])) ?>"><?= e($row['title']) ?></a>
            <span class="ad-slug">/<?= e($row['slug']) ?>.html</span>
          </td>
          <td><?= ad_status_label($row['status']) ?></td>
          <td><?= (int) $row['views'] ?></td>
          <td class="ad-muted"><?= e(qn_time_ago((string) $row['updated_at'])) ?></td>
          <td class="ad-cell-op">
            <a class="ad-icon-btn" href="<?= $row['status'] === 'public' ? e(doc_url($row)) : e(admin_url('editor.php?id=' . (int) $row['id'])) ?>"
               target="_blank" rel="noopener" title="预览"><?= qn_icon('eye') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php ad_card_close(); ?>

  <?php ad_card_open('运行环境'); ?>
    <ul class="ad-kv">
      <li><span>PHP 版本</span><b><?= e($info['php']) ?></b></li>
      <li><span>MySQL 版本</span><b><?= e($info['mysql']) ?></b></li>
      <li><span>Web 服务器</span><b class="ad-wrap-any"><?= e($info['server']) ?></b></li>
      <li><span>站点域名</span><b><?= e((string) opt('site_domain', '')) ?></b></li>
      <li><span>前台主题</span><b><?= e(qn_theme_name()) ?></b></li>
      <li><span>伪静态</span><b><?= qn_rewrite() ? '已启用' : '未启用（兼容模式）' ?></b></li>
      <li><span>静态缓存</span><b><?= (int) opt('static_cache', 1) === 1 ? '开启' : '关闭' ?></b></li>
      <li><span>版本修订</span><b><?= $stats['revisions'] ?> 条</b></li>
      <li><span>成员账号</span><b><?= $stats['users'] ?> 个</b></li>
    </ul>
    <div class="ad-card-links">
      <a href="<?= e(admin_url('tools.php')) ?>">重新生成静态页面</a>
      <a href="<?= e(admin_url('settings.php')) ?>">站点设置</a>
    </div>
  <?php ad_card_close(); ?>
</div>

<?php ad_card_open('浏览器'); ?>
  <div class="ad-chips">
    <?php foreach ($popular as $row): ?>
      <a class="ad-chip" href="<?= e(doc_url($row)) ?>" target="_blank" rel="noopener">
        <?= e($row['title']) ?><em><?= (int) $row['views'] ?></em>
      </a>
    <?php endforeach; ?>
    <?php if (!$popular): ?><span class="ad-muted">暂无数据</span><?php endif; ?>
  </div>
<?php ad_card_close(); ?>

<?php admin_foot(); ?>
