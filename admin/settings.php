<?php
/**
 * Qndocs - 站点设置
 */

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();

    $texts = [
        'site_name'     => 80,
        'site_desc'     => 200,
        'site_keywords' => 200,
        'site_domain'   => 120,
    ];
    foreach ($texts as $key => $length) {
        set_opt($key, mb_substr(trim((string) post($key)), 0, $length, 'UTF-8'));
    }

    set_opt('head_code', (string) post('head_code'));
    set_opt('home_doc_id', (string) max(0, (int) post('home_doc_id')));
    set_opt('per_page', (string) max(5, min(100, (int) post('per_page', 20))));
    set_opt('static_cache', post('static_cache') ? '1' : '0');
    set_opt('rewrite', post('rewrite') ? '1' : '0');
    set_opt('rewrite_nginx', post('rewrite_nginx') ? '1' : '0');
    set_opt('site_mode', post('site_mode') === 'integrated' ? 'integrated' : 'standalone');

    // 首页标记同步
    $homeId = (int) post('home_doc_id');
    DB::exec('UPDATE {docs} SET is_home = 0');
    if ($homeId > 0) {
        DB::update('docs', ['is_home' => 1], 'id = ?', [$homeId]);
    }

    $stat = qn_rebuild_static();
    flash('站点设置已保存，重新生成静态页面 ' . $stat['ok'] . ' 个。');
    redirect(admin_url('settings.php'));
}

$docs     = DB::all('SELECT id, title, slug, parent_id FROM {docs} ORDER BY sort ASC, id ASC');
$homeId   = (int) opt('home_doc_id', 0);
$rewriteOk = qn_rewrite();

admin_head('站点设置', 'settings.php');
?>

<form method="post" class="ad-form-page" action="<?= e(admin_url('settings.php')) ?>">
  <?= csrf_field() ?>
  <div class="ad-grid-2">

    <?php ad_card_open('基本信息'); ?>
      <div class="ad-form">
        <label><span>站点名称</span><input class="ad-input" type="text" name="site_name" value="<?= e((string) opt('site_name', 'Qndocs')) ?>" required></label>
        <label><span>站点简介</span><input class="ad-input" type="text" name="site_desc" value="<?= e((string) opt('site_desc', '')) ?>"></label>
        <label><span>站点关键词（逗号分隔）</span><input class="ad-input" type="text" name="site_keywords" value="<?= e((string) opt('site_keywords', '')) ?>"></label>
        <label><span>站点域名</span><input class="ad-input" type="text" name="site_domain" value="<?= e((string) opt('site_domain', '')) ?>" placeholder="docs.example.com"></label>
        <p class="ad-hint">留空则自动使用当前访问的域名，一般不用填。</p>
        <p class="ad-hint">站点名称与简介只用于后台显示、文档的 SEO 描述和 <code>/api.php</code> 返回信息；文档页面本身不会插入任何页头页脚。</p>
      </div>
    <?php ad_card_close(); ?>

    <?php ad_card_open('高级'); ?>
      <div class="ad-form">
        <label><span>自定义 &lt;head&gt; 代码（会插入每篇独立文档的 head 中，可留空）</span>
          <textarea class="ad-input code" name="head_code" rows="6" spellcheck="false"><?= e((string) opt('head_code', '')) ?></textarea>
        </label>
      </div>
    <?php ad_card_close(); ?>

    <?php ad_card_open('输出模式'); ?>
      <div class="ad-form">
        <label><span>前台输出方式</span>
          <select name="site_mode">
            <option value="standalone" <?= opt('site_mode', 'standalone') === 'standalone' ? 'selected' : '' ?>>独立文档（默认）：每篇只有内容，无页头页脚，适合嵌进软件</option>
            <option value="integrated" <?= opt('site_mode', 'standalone') === 'integrated' ? 'selected' : '' ?>>整合文档站：页头 + 侧边目录 + 右栏目录 + 页脚，适合浏览器浏览</option>
          </select>
        </label>
        <p class="ad-hint">两种模式共用同一批文档与同一套样式（样式中心的变量两边都生效），随时可切换；切换后静态页面会自动重建。</p>
        <p class="ad-hint">另外，任何一篇文档都能按需取用四种形式：<code>/slug.html</code>（独立整页）、<code>?fragment=1</code>（样式 + 正文）、<code>?bare=1</code>（只有正文）、<code>?text=1</code>（纯文本）。</p>
      </div>
    <?php ad_card_close(); ?>

    <?php ad_card_open('首页与列表'); ?>
      <div class="ad-form">
        <label><span>首页文档（访问站点根目录时显示哪一篇）</span>
          <select name="home_doc_id">
            <option value="0">自动（第一篇被标记为首页的公开文档）</option>
            <?php foreach ($docs as $row): ?>
              <option value="<?= (int) $row['id'] ?>" <?= $homeId === (int) $row['id'] ? 'selected' : '' ?>>
                <?= e($row['title']) ?>（/<?= e($row['slug']) ?>.html）
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span>列表每页数量</span><input class="ad-input" type="number" name="per_page" value="<?= (int) opt('per_page', 20) ?>" min="5" max="100"></label>
      </div>
    <?php ad_card_close(); ?>

    <?php ad_card_open('性能与地址'); ?>
      <div class="ad-form">
        <label class="ad-check"><input type="checkbox" name="static_cache" value="1" <?= (int) opt('static_cache', 1) === 1 ? 'checked' : '' ?>> <span>生成并输出静态 HTML 页面（推荐）</span></label>
        <p class="ad-hint">开启后，公开文档会生成 <code>data/pages/{slug}.html</code> 并优先直接输出，速度更快；文档保存或样式修改时会自动重新生成。</p>

        <label class="ad-check"><input type="checkbox" name="rewrite" value="1" <?= (int) opt('rewrite', 0) === 1 ? 'checked' : '' ?>> <span>URL 伪静态（<code>/doc.html</code> 形式，更美观）</span></label>

        <?php if (qn_server_is_nginx()): ?>
          <p class="ad-hint">
            当前服务器是 <strong>nginx</strong> —— 它<strong>不会读取 .htaccess</strong>。请先在站点配置（宝塔：网站 → 设置 → 配置文件）里加入下面的规则，再勾选最下方的确认项；
            <strong>否则文档链接会 404</strong>。
          </p>
          <pre class="ad-pre">location / {
    try_files $uri $uri/ /index.php?p=$uri&$args;
}</pre>
          <label class="ad-check"><input type="checkbox" name="rewrite_nginx" value="1" <?= (int) opt('rewrite_nginx', 0) === 1 ? 'checked' : '' ?>> <span>我已按上面的规则配置了 Nginx 重写（勾选后伪静态才会真正生效）</span></label>
        <?php else: ?>
          <p class="ad-hint">服务器为 Apache 系，安装时已生成 <code>.htaccess</code>，直接勾选上面的开关即可。</p>
        <?php endif; ?>

        <div class="ad-actions-row">
          <button type="button" class="ad-btn ghost sm" id="adRewriteTest"
                  data-probe="<?= e(qn_url('__qndocs_rewrite_probe.html')) ?>">检测伪静态是否生效</button>
          <span class="ad-muted" id="adRewriteResult">
            当前：<?= $rewriteOk ? '<span class="ad-badge ok">伪静态已生效</span>' : '<span class="ad-badge warn">兼容模式 index.php?p=xxx.html</span>' ?>
          </span>
        </div>
        <p class="ad-hint">检测会请求一个不存在的 <code>.html</code> 地址：能进入 Qndocs 的 404 页面就说明重写已生效。</p>
      </div>
    <?php ad_card_close(); ?>
  </div>

  <div class="ad-sticky-actions">
    <button class="ad-btn" type="submit"><?= qn_icon('save') ?>保存设置</button>
    <a class="ad-btn ghost" href="<?= e(admin_url('style.php')) ?>">前往样式中心</a>
  </div>
</form>

<?php admin_foot(); ?>
