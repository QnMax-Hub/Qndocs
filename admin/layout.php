<?php
/**
 * Qndocs - 后台布局与视图组件
 */

function qn_icon(string $name, string $class = ''): string
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7.2" height="7.2" rx="1.6"/><rect x="13.8" y="3" width="7.2" height="7.2" rx="1.6"/><rect x="3" y="13.8" width="7.2" height="7.2" rx="1.6"/><rect x="13.8" y="13.8" width="7.2" height="7.2" rx="1.6"/>',
        'docs'      => '<path d="M6.5 3h7.6L19 7.9V21H6.5z"/><path d="M13.8 3v5.1h5"/>',
        'style'     => '<circle cx="12" cy="12" r="8.6"/><circle cx="9.2" cy="9.6" r="1.1"/><circle cx="14.8" cy="9.6" r="1.1"/><circle cx="12" cy="14.9" r="1.1"/>',
        'media'     => '<rect x="3" y="4.2" width="18" height="15.6" rx="2.2"/><circle cx="8.4" cy="9.4" r="1.5"/><path d="M20.8 15.6l-4.6-4.6-5.6 5.6-2.8-2.8-4.6 4.6"/>',
        'revisions' => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7.2V12l3.4 2.1"/>',
        'users'     => '<circle cx="9.4" cy="8.4" r="3.2"/><path d="M3.6 20c0-3 2.6-5.1 5.8-5.1s5.8 2.1 5.8 5.1"/><path d="M16.2 5.4a3 3 0 010 6"/><path d="M18.4 20c0-2.3-.7-4.1-1.9-5.2"/>',
        'settings'  => '<circle cx="12" cy="12" r="3.1"/><path d="M12 3.2v2.2M12 18.6v2.2M3.2 12h2.2M18.6 12h2.2M5.8 5.8l1.6 1.6M16.6 16.6l1.6 1.6M18.2 5.8l-1.6 1.6M7.4 16.6l-1.6 1.6"/>',
        'tools'     => '<path d="M14.9 6.1a4.1 4.1 0 105.3 5.3l-8.2 8.2a2.1 2.1 0 01-3-3l8.2-8.2z"/>',
        'logout'    => '<path d="M15 4.2h4.2V20H15"/><path d="M11 8.2L7.2 12l3.8 3.8"/><path d="M7.2 12h8.4"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'search'    => '<circle cx="10.8" cy="10.8" r="6.3"/><path d="M15.4 15.4L20 20"/>',
        'save'      => '<path d="M5 3.5h10.4L19 7.1V20.5H5z"/><path d="M8.4 3.5v5.6h6.6V3.5"/><path d="M8.4 20.5v-6h7.2v6"/>',
        'trash'     => '<path d="M4.6 6.8h14.8"/><path d="M9.4 6.8V4.6h5.2v2.2"/><path d="M6.6 6.8l.9 13h9l.9-13"/>',
        'edit'      => '<path d="M4 20h4l10.2-10.2-4-4L4 16z"/><path d="M13.6 6.2l4 4"/>',
        'up'        => '<path d="M12 19V5M5.6 11.4L12 5l6.4 6.4"/>',
        'down'      => '<path d="M12 5v14M5.6 12.6L12 19l6.4-6.4"/>',
        'eye'       => '<path d="M2.6 12S6 6.2 12 6.2 21.4 12 21.4 12 18 17.8 12 17.8 2.6 12 2.6 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'copy'      => '<rect x="8.6" y="8.6" width="11.4" height="11.4" rx="2"/><path d="M15.4 5.4H5.6a1.6 1.6 0 00-1.6 1.6v9.8"/>',
        'check'     => '<path d="M5 12.8l4.6 4.6L19 6.6"/>',
        'refresh'   => '<path d="M20 12a8 8 0 11-2.6-5.9"/><path d="M20 4.4V10h-5.6"/>',
        'upload'    => '<path d="M12 16.6V4.6"/><path d="M7.4 9.2L12 4.6l4.6 4.6"/><path d="M4.6 15.6v2.8a2 2 0 002 2h10.8a2 2 0 002-2v-2.8"/>',
        'home'      => '<path d="M4 10.6L12 4l8 6.6V20H4z"/><path d="M9.6 20v-6h4.8v6"/>',
        'lock'      => '<rect x="5" y="10.6" width="14" height="9.4" rx="2"/><path d="M8.4 10.6V8a3.6 3.6 0 017.2 0v2.6"/>',
    ];
    $path = $paths[$name] ?? '';
    return '<svg class="ad-ic' . ($class !== '' ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $path . '</svg>';
}

function admin_menu(): array
{
    return [
        'index.php'     => ['仪表盘', 'dashboard'],
        'docs.php'      => ['文档管理', 'docs'],
        'style.php'     => ['样式中心', 'style'],
        'media.php'     => ['媒体库', 'media'],
        'revisions.php' => ['修订历史', 'revisions'],
        'users.php'     => ['用户管理', 'users'],
        'settings.php'  => ['站点设置', 'settings'],
        'tools.php'     => ['工具', 'tools'],
    ];
}

function admin_head(string $title, string $active = '', string $actions = ''): void
{
    $site  = (string) opt('site_name', 'Qndocs');
    $vars  = qn_css_vars();
    $user  = qn_current_user();
    $name  = $user ? ($user['nickname'] !== '' ? $user['nickname'] : $user['username']) : '';
    $theme = qn_theme_name();
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · <?= e($site) ?> 后台</title>
<?php if (!qn_plain_mode()): ?>
<link rel="stylesheet" href="<?= e(qn_asset('admin.css')) ?>">
<style>:root{
  --primary: <?= e($vars['primary'] ?? '#2563eb') ?>;
  --primary-dark: <?= e($vars['primary-dark'] ?? '#1d4ed8') ?>;
  --primary-soft: <?= e($vars['primary-soft'] ?? '#e8f0fe') ?>;
  --radius: <?= e($vars['radius'] ?? '10px') ?>;
  --radius-sm: <?= e($vars['radius-sm'] ?? '6px') ?>;
}</style>
<?php if (strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'editor.php') !== false): ?>
<?php /* 编辑器内直接套用文档样式，实现真正的所见即所得 */ ?>
<style><?= qn_theme_css() ?></style>
<?php endif; ?>
<?php else: ?>
<style>body{font:15px/1.7 system-ui,sans-serif;margin:0;padding:14px}.ad-shell,.ad-wrap{display:block}.ad-side{border-bottom:1px solid #ddd;padding:8px 0}.ad-nav-item{display:inline-block;margin-right:12px}.ad-top{display:flex;gap:12px;align-items:center}.ad-note{background:#fffbe6;border:1px solid #fde68a;padding:8px 12px;border-radius:6px;margin-bottom:12px}</style>
<?php endif; ?>
</head>
<body class="ad">
<?php if (qn_plain_mode()): ?>
<div class="ad-note">已开启<strong>极简模式</strong>（?plain=1）：本页未加载任何样式与脚本。若这里能看到完整内容，说明后台服务端正常，问题出在样式/脚本加载或浏览器渲染上。</div>
<?php endif; ?>
<div class="ad-shell">

  <aside class="ad-side" id="adSide">
    <a class="ad-logo" href="<?= e(admin_url('index.php')) ?>">
      <span class="ad-logo-mark">QN</span>
      <span class="ad-logo-text"><strong>Qndocs</strong><em>内容后台</em></span>
    </a>
    <nav class="ad-nav">
      <?php foreach (admin_menu() as $file => $item): ?>
        <a class="ad-nav-item<?= $active === $file ? ' on' : '' ?>" href="<?= e(admin_url($file)) ?>">
          <?= qn_icon($item[1]) ?><span><?= e($item[0]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="ad-side-foot">
      <div class="ad-side-tip">当前主题：<?= e($theme) ?></div>
      <a class="ad-nav-item" href="<?= e(admin_url('logout.php')) ?>"><?= qn_icon('logout') ?><span>退出登录</span></a>
    </div>
  </aside>

  <div class="ad-wrap">
    <header class="ad-top">
      <button class="ad-burger" id="adBurger" type="button" aria-label="菜单">☰</button>
      <h1 class="ad-title"><?= e($title) ?></h1>
      <div class="ad-top-actions">
        <?= $actions ?>
        <a class="ad-btn ghost sm" href="<?= e(qn_url('')) ?>" target="_blank" rel="noopener"><?= qn_icon('eye') ?>前台</a>
        <span class="ad-user" title="<?= e($user['username'] ?? '') ?>"><?= e(mb_substr($name, 0, 1, 'UTF-8')) ?></span>
      </div>
    </header>

    <?= flash_render() ?>
    <main class="ad-body">
    <?php
}

function admin_foot(): void
{
    ?>
    </main>
    <footer class="ad-foot">
      <span>Qndocs v<?= e(QN_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></span>
      <span><a href="<?= e(admin_url('settings.php')) ?>">站点设置</a> · <a href="<?= e(qn_url('')) ?>" target="_blank" rel="noopener">访问前台</a></span>
    </footer>
  </div>
</div>
<?php if (!qn_plain_mode()): ?>
<script src="<?= e(qn_asset('admin.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
    <?php
}

function ad_card_open(string $title = '', string $extraClass = ''): void
{
    echo '<section class="ad-card ' . e($extraClass) . '">';
    if ($title !== '') {
        echo '<header class="ad-card-hd"><h2>' . e($title) . '</h2></header>';
    }
    echo '<div class="ad-card-bd">';
}

function ad_card_close(): void
{
    echo '</div></section>';
}

/** 文档状态标签 */
function ad_status_label(string $status): string
{
    $map = [
        'public'   => ['公开', 'ok'],
        'password' => ['密码', 'warn'],
        'private'  => ['私有', 'info'],
        'draft'    => ['草稿', 'muted'],
    ];
    $item = $map[$status] ?? ['未知', 'muted'];
    return '<span class="ad-badge ' . $item[1] . '">' . e($item[0]) . '</span>';
}

/** 文档树（后台，扁平输出带 depth） */
function ad_doc_rows(?int $parent = 0, int $depth = 0, ?array $all = null): array
{
    if ($all === null) {
        $all = DB::all('SELECT * FROM {docs} ORDER BY sort ASC, id ASC');
    }
    $group = [];
    foreach ($all as $row) {
        $group[(int) $row['parent_id']][] = $row;
    }
    $walk = function ($pid, $level) use (&$walk, $group) {
        $out = [];
        foreach ($group[$pid] ?? [] as $row) {
            $row['depth'] = $level;
            $out[]        = $row;
            $out          = array_merge($out, $walk((int) $row['id'], $level + 1));
        }
        return $out;
    };
    return $walk((int) $parent, $depth);
}
