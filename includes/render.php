<?php
/**
 * Qndocs - 渲染层：文档输出、页面上下文、静态 HTML 生成
 */

/* --------------------------------------------------------------- 主题 */

function qn_theme_name(): string
{
    $name = (string) opt('theme', 'linear-blue');
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
    return $name === '' ? 'linear-blue' : $name;
}

function qn_theme_dir(): string
{
    $dir = QN_ROOT . '/themes/' . qn_theme_name();
    return is_file($dir . '/doc.php') ? $dir : QN_ROOT . '/themes/linear-blue';
}

function qn_themes(): array
{
    $out = [];
    foreach (glob(QN_ROOT . '/themes/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file($dir . '/doc.php')) {
            continue;
        }
        $name = basename($dir);
        $meta = is_file($dir . '/theme.json') ? json_decode((string) file_get_contents($dir . '/theme.json'), true) : [];
        $out[$name] = [
            'name'  => $name,
            'title' => is_array($meta) && !empty($meta['title']) ? $meta['title'] : $name,
            'desc'  => is_array($meta) && isset($meta['desc']) ? $meta['desc'] : '',
        ];
    }
    return $out;
}

/** 文档样式 + 后台可调变量 + 自定义 CSS（合并后内联输出） */
function qn_theme_css(bool $withSite = true): string
{
    $css  = '';
    $file = qn_theme_dir() . '/style.css';
    if (is_file($file)) {
        $css .= (string) file_get_contents($file) . "\n";
    }

    // 整合文档站模式：额外加载站点框架样式（页头/侧栏/目录/页脚）
    // 片段模式（$withSite = false）只需要文档本身样式，不带整套框架样式
    if ($withSite && (string) opt('site_mode', 'standalone') === 'integrated') {
        $siteCss = qn_theme_dir() . '/site.css';
        if (is_file($siteCss)) {
            $css .= (string) file_get_contents($siteCss) . "\n";
        }
    }

    $vars  = qn_css_vars();
    $lines = [];
    foreach ($vars as $key => $value) {
        $key = (string) $key;
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $key)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $value = str_replace(['{', '}', '<', '>', ';'], '', $value);
        $lines[] = '  --' . $key . ': ' . $value . ';';
    }
    if ($lines) {
        // 变量写在 .qn-doc 上（而不是 :root），片段嵌入宿主页面时不会污染其样式；
        // 整合文档站模式下，框架（.qn-site）也需要同一套变量
        $selector = (string) opt('site_mode', 'standalone') === 'integrated' ? '.qn-doc, .qn-site' : '.qn-doc';
        $css .= "\n/* 后台自定义变量 */\n" . $selector . " {\n" . implode("\n", $lines) . "\n}\n";
    }

    $custom = trim((string) opt('css_custom', ''));
    if ($custom !== '') {
        $css .= "\n/* 后台自定义 CSS */\n" . str_replace('</style', '<\/style', $custom) . "\n";
    }

    return $css;
}

/* --------------------------------------------------------------- 数据 */

function qn_find_doc(string $slug, bool $includeDraft = false)
{
    if ($slug === '') {
        return null;
    }
    $sql    = 'SELECT * FROM {docs} WHERE slug = ?';
    $params = [$slug];
    if (!$includeDraft) {
        $sql .= " AND status <> 'draft'";
    }
    $doc = DB::one($sql . ' LIMIT 1', $params);
    if ($doc) {
        $doc['tags'] = qn_doc_tags($doc);
    }
    return $doc;
}

function qn_find_doc_id(int $id, bool $includeDraft = false)
{
    if ($id <= 0) {
        return null;
    }
    $sql = 'SELECT * FROM {docs} WHERE id = ?';
    if (!$includeDraft) {
        $sql .= " AND status <> 'draft'";
    }
    $doc = DB::one($sql . ' LIMIT 1', [$id]);
    if ($doc) {
        $doc['tags'] = qn_doc_tags($doc);
    }
    return $doc;
}

function qn_doc_tags(?array $doc): array
{
    if (!$doc) {
        return [];
    }
    $key = 'qn_doc_tags_' . (int) $doc['id'];
    if (isset($GLOBALS[$key])) {
        return $GLOBALS[$key];
    }
    $rows = DB::all('SELECT t.name FROM {tags} t JOIN {doc_tags} dt ON dt.tag_id = t.id WHERE dt.doc_id = ? ORDER BY t.id', [(int) $doc['id']]);
    $out  = [];
    foreach ($rows as $row) {
        $out[] = $row['name'];
    }
    $GLOBALS[$key] = $out;
    return $out;
}

function qn_sync_tags(int $docId, array $names): void
{
    DB::exec('DELETE FROM {doc_tags} WHERE doc_id = ?', [$docId]);
    foreach ($names as $name) {
        $name = trim(mb_substr((string) $name, 0, 40, 'UTF-8'));
        if ($name === '') {
            continue;
        }
        $tag = DB::one('SELECT id FROM {tags} WHERE name = ?', [$name]);
        if ($tag) {
            $tagId = (int) $tag['id'];
        } else {
            $tagId = DB::insert('tags', ['name' => $name, 'slug' => qn_slugify($name, 'tag')]);
        }
        DB::exec('REPLACE INTO {doc_tags} (doc_id, tag_id) VALUES (?, ?)', [$docId, $tagId]);
    }
}

function qn_home_doc()
{
    $id = (int) opt('home_doc_id', 0);
    if ($id > 0) {
        $doc = qn_find_doc_id($id);
        if ($doc) {
            return $doc;
        }
    }
    $doc = DB::one("SELECT * FROM {docs} WHERE is_home = 1 AND status = 'public' ORDER BY id ASC LIMIT 1");
    if ($doc) {
        $doc['tags'] = qn_doc_tags($doc);
    }
    return $doc;
}

function qn_public_docs(int $limit = 0, int $offset = 0): array
{
    $sql = "SELECT id,parent_id,title,slug,sort,type,status,description,updated_at,views FROM {docs} WHERE status IN ('public','password') ORDER BY sort ASC, id ASC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) $offset);
    }
    return DB::all($sql);
}

function qn_count_public_docs(): int
{
    return (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status IN ('public','password')");
}

/** 某个标签下的公开文档 */
function qn_docs_by_tag(string $slug, int $limit = 100): array
{
    if ($slug === '') {
        return [];
    }
    return DB::all(
        "SELECT d.id, d.parent_id, d.title, d.slug, d.description, d.updated_at, d.views
         FROM {docs} d
         JOIN {doc_tags} dt ON dt.doc_id = d.id
         JOIN {tags} t ON t.id = dt.tag_id
         WHERE t.slug = ? AND d.status = 'public'
         ORDER BY d.sort ASC, d.id ASC LIMIT " . (int) $limit,
        [$slug]
    );
}

function qn_find_tag(string $slug)
{
    if ($slug === '') {
        return null;
    }
    return DB::one('SELECT * FROM {tags} WHERE slug = ? LIMIT 1', [$slug]);
}

function qn_search_docs(string $keyword, int $limit = 30): array
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return [];
    }
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
    return DB::all(
        "SELECT id,parent_id,title,slug,description,updated_at,content,type FROM {docs}
         WHERE status = 'public' AND (title LIKE ? OR content LIKE ? OR description LIKE ? OR keywords LIKE ?)
         ORDER BY (title LIKE ?) DESC, updated_at DESC LIMIT " . (int) $limit,
        [$like, $like, $like, $like, $like]
    );
}

/** 同层级下的上一篇 / 下一篇（整合模式用） */
function qn_sibling_docs(?array $doc): array
{
    if (!$doc) {
        return ['prev' => null, 'next' => null];
    }
    $rows = DB::all(
        "SELECT id,parent_id,title,slug FROM {docs} WHERE parent_id = ? AND status IN ('public','password') ORDER BY sort ASC, id ASC",
        [(int) $doc['parent_id']]
    );
    $prev = null;
    $next = null;
    foreach ($rows as $index => $row) {
        if ((int) $row['id'] === (int) $doc['id']) {
            $prev = $index > 0 ? $rows[$index - 1] : null;
            $next = isset($rows[$index + 1]) ? $rows[$index + 1] : null;
            break;
        }
    }
    return ['prev' => $prev, 'next' => $next];
}

/* --------------------------------------------------------------- 树 */

/** 导航树（只含「显示在导航里」的已发布文档） */
function qn_nav_tree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }
    $tree = [];
    if (!DB::ready()) {
        return $tree;
    }
    $rows = DB::all("SELECT id,parent_id,title,slug FROM {docs}
                     WHERE status IN ('public','password') AND in_nav = 1
                     ORDER BY sort ASC, id ASC");
    $group = [];
    foreach ($rows as $row) {
        $group[(int) $row['parent_id']][] = $row;
    }
    $build = function ($parent, $depth) use (&$build, $group) {
        $out = [];
        foreach ($group[$parent] ?? [] as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'title'    => (string) $row['title'],
                'slug'     => (string) $row['slug'],
                'depth'    => $depth,
                'children' => $build((int) $row['id'], $depth + 1),
            ];
        }
        return $out;
    };
    $tree = $build(0, 0);
    return $tree;
}

/** 面包屑：从根到当前文档父级的链路 */
function qn_breadcrumb(?array $doc): array
{
    if (!$doc) {
        return [];
    }
    $out = [];
    $id  = (int) $doc['parent_id'];
    $hop = 0;
    while ($id > 0 && $hop++ < 20) {
        $row = DB::one("SELECT id,parent_id,title,slug FROM {docs} WHERE id = ? AND status <> 'draft'", [$id]);
        if (!$row) {
            break;
        }
        array_unshift($out, $row);
        $id = (int) $row['parent_id'];
    }
    return $out;
}

/* --------------------------------------------------------------- 正文 */

function qn_doc_content(?array $doc): string
{
    if (!$doc) {
        return '';
    }
    $content = (string) $doc['content'];
    if ($doc['type'] === 'markdown') {
        return qn_md($content);
    }
    if ($doc['type'] === 'text') {
        // 纯文本文档：保留原有换行与空格
        return '<pre class="qn-text">' . e($content) . '</pre>';
    }
    return $content;
}

/** 把 HTML 转成可读纯文本（块级标签边界变换行） */
function qn_html_to_text(string $html): string
{
    $text = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/div|/blockquote|/pre|/table)[^>]*>#i', "\n", $html);
    $text = (string) preg_replace('#<li[^>]*>#i', '- ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/** 提取文档的纯文本（供 ?text=1 输出与接口使用） */
function qn_doc_plain(?array $doc): string
{
    if (!$doc) {
        return '';
    }
    $content = (string) $doc['content'];
    if ($doc['type'] === 'html') {
        return qn_html_to_text($content);
    }
    return $content;   // text / markdown 本身近似纯文本
}

function qn_page_context($doc = null, array $extra = []): array
{
    $siteName = (string) opt('site_name', 'Qndocs');

    $ctx = [
        'site' => [
            'name'     => $siteName,
            'desc'     => (string) opt('site_desc', ''),
            'keywords' => (string) opt('site_keywords', ''),
            'domain'   => (string) opt('site_domain', ''),
            'head'     => (string) opt('head_code', ''),
            'footer'   => (string) opt('site_footer', ''),
            'width'    => (int) opt('doc_width', 860),
            'base'     => qn_seg(),
            'version'  => QN_VERSION,
        ],
        'css'       => qn_theme_css(),
        'theme'     => qn_theme_name(),
        'doc'       => $doc,
        'mode'      => $doc ? 'doc' : 'home',
        'body'      => '',
        'title'     => $siteName,
        'desc'      => (string) opt('site_desc', ''),
        'keywords'  => (string) opt('site_keywords', ''),
        'canonical' => '',
        'foot_code' => '',
        'hit_id'    => 0,
        'docs'      => [],
        'results'   => [],
        'tag'       => null,
        'keyword'   => '',
        'total'     => 0,
        'page'      => 1,
        'per_page'  => (int) opt('per_page', 20),
        'pw_error'  => '',
        'nav'       => [],
        'breadcrumb' => [],
        'siblings'  => ['prev' => null, 'next' => null],
        'site_mode' => (string) opt('site_mode', 'standalone'),
    ];

    foreach ($extra as $key => $value) {
        $ctx[$key] = $value;
    }

    if ($doc) {
        $content          = qn_doc_content($doc);
        $ctx['body']      = $content;
        $ctx['title']     = (string) $doc['title'];   // 文档标题本身就是页面标题
        $ctx['desc']      = $doc['description'] !== '' ? (string) $doc['description'] : qn_text($content, 140);
        $ctx['keywords']  = $doc['keywords'] !== '' ? (string) $doc['keywords'] : (string) opt('site_keywords', '');
        $ctx['hit_id']    = (int) $doc['id'];
        $ctx['breadcrumb'] = qn_breadcrumb($doc);
        $ctx['siblings']   = qn_sibling_docs($doc);
    } else {
        $ctx['body']  = qn_build_body($ctx);
        $ctx['title'] = qn_mode_title($ctx);
    }

    // 整合文档站模式才需要导航树
    if ((string) $ctx['site_mode'] === 'integrated') {
        $ctx['nav'] = qn_nav_tree();
    }

    return $ctx;
}

/** 各模式（列表 / 搜索 / 404 / 密码…）的页面标题 */
function qn_mode_title(array $ctx): string
{
    $siteName = (string) ($ctx['site']['name'] ?? 'Qndocs');
    switch ((string) $ctx['mode']) {
        case 'list':
            return '全部文档 - ' . $siteName;
        case 'search':
            return '搜索文档 - ' . $siteName;
        case 'tag':
            return '标签 - ' . $siteName;
        case 'password':
            return '需要密码 - ' . $siteName;
        case '403':
            return '私有文档 - ' . $siteName;
        case '404':
            return '页面不存在 - ' . $siteName;
    }
    return $siteName;
}

/** 文档以外模式的正文 HTML */
function qn_build_body(array $ctx): string
{
    $mode = (string) $ctx['mode'];

    if ($mode === 'list') {
        $docs = (array) $ctx['docs'];
        return '<h1>全部文档</h1><p class="qn-muted">共 ' . (int) $ctx['total'] . ' 篇</p>' . qn_doc_list_html($docs);
    }

    if ($mode === 'search') {
        $kw   = (string) $ctx['keyword'];
        $list = [];
        foreach ((array) $ctx['results'] as $row) {
            if (empty($row['description'])) {
                $row['description'] = qn_text((string) ($row['content'] ?? ''), 110);
            }
            $list[] = $row;
        }
        $html = '<h1>搜索文档</h1><p class="qn-muted">关键词：' . e($kw) . '，共 ' . count($list) . ' 条结果</p>';
        return $html . ($list ? qn_doc_list_html($list) : '<p class="qn-muted">没有找到相关文档。</p>');
    }

    if ($mode === 'tag') {
        $tag = $ctx['tag'];
        return '<h1>标签：' . e($tag['name']) . '</h1>' . qn_doc_list_html((array) $ctx['docs']);
    }

    if ($mode === 'password') {
        $doc  = $ctx['doc'];
        $html = '<h1>该文档受密码保护</h1><p class="qn-muted">请输入访问密码后查看内容。</p>';
        if (!empty($ctx['pw_error'])) {
            $html .= '<div class="qn-alert">' . e($ctx['pw_error']) . '</div>';
        }
        return $html
            . '<form method="post" action="' . e($doc ? doc_url($doc) : qn_url('')) . '">'
            . '<p><input class="qn-input" type="password" name="doc_password" placeholder="请输入访问密码" required autofocus></p>'
            . '<p><button class="qn-btn" type="submit">进入文档</button></p></form>';
    }

    if ($mode === '403') {
        return '<h1>这是私有文档</h1><p class="qn-muted">该文档仅对已登录的成员开放。</p>';
    }

    if ($mode === '404') {
        return '<h1>页面不存在</h1><p class="qn-muted">你访问的文档可能已被移动或删除。</p>';
    }

    // home：没有指定首页文档时，列出全部文档
    return '<h1>' . e((string) $ctx['site']['name']) . '</h1>'
        . '<p class="qn-muted">' . e((string) $ctx['site']['desc']) . '</p>'
        . qn_doc_list_html(qn_public_docs(100));
}

/** 渲染成独立完整页面（只有文档本体，无任何框架元素） */
function qn_render_page(array $ctx): string
{
    $dir = qn_theme_dir();

    // 站点级输出模式：独立文档（默认）/ 整合文档站
    $file = $dir . '/doc.php';
    if ((string) ($ctx['site_mode'] ?? opt('site_mode', 'standalone')) === 'integrated' && is_file($dir . '/site.php')) {
        $file = $dir . '/site.php';
    }
    if (!is_file($file)) {
        return '';
    }
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

/** 片段模式：只输出「样式 + 正文」，方便直接嵌入自己的软件界面 */
function qn_render_fragment(array $ctx): string
{
    // 片段是给宿主软件嵌入用的：只要文档本身样式，不带整合文档站的框架样式
    $ctx['css'] = qn_theme_css(false);
    $file = qn_theme_dir() . '/fragment.php';
    if (!is_file($file)) {
        return '<style>' . $ctx['css'] . '</style><article class="qn-doc" id="qnDoc">' . $ctx['body'] . '</article>';
    }
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

/* --------------------------------------------------------------- 静态 */

function qn_static_path($doc): string
{
    $slug = is_array($doc) ? (string) $doc['slug'] : (string) $doc;
    $slug = qn_slugify($slug, is_array($doc) ? 'doc-' . (int) $doc['id'] : 'index');
    return QN_DATA . '/pages/' . $slug . '.html';
}

/** 把渲染结果写入静态文件（自动带缓存标记） */
function qn_write_static($doc, string $html): bool
{
    if (!DB::ready() || !is_array($doc)) {
        return false;
    }
    if ((int) opt('static_cache', 1) !== 1 || $doc['status'] !== 'public') {
        return false;
    }
    $marker = qn_cache_marker();
    if (stripos($html, '<head>') !== false) {
        $html = (string) preg_replace('/<head>/i', $marker . '<head>', $html, 1);
    } else {
        $html = $marker . $html;
    }
    return qn_try_write(qn_static_path($doc), $html);
}

function qn_build_static($doc): bool
{
    if (!DB::ready() || !is_array($doc)) {
        return false;
    }
    if ((int) opt('static_cache', 1) !== 1) {
        return false;
    }
    if ($doc['status'] !== 'public') {
        @unlink(qn_static_path($doc));
        return false;
    }
    return qn_write_static($doc, qn_render_page(qn_page_context($doc)));
}

/** 返回可用的静态缓存文件，未命中返回 null */
function qn_static_hit($doc): ?string
{
    if (!is_array($doc) || (int) opt('static_cache', 1) !== 1 || $doc['status'] !== 'public') {
        return null;
    }
    $file = qn_static_path($doc);
    if (!is_file($file)) {
        return null;
    }
    $mtime = (int) filemtime($file);
    $utime = strtotime((string) $doc['updated_at']) ?: 0;
    if ($mtime < $utime) {
        return null;
    }
    // 缓存必须由当前部署路径与版本生成，否则忽略（会自动重新生成）
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return null;
    }
    $head = (string) fread($handle, 256);
    fclose($handle);
    if (strpos($head, qn_cache_marker()) === false) {
        return null;
    }
    return $file;
}

function qn_purge_static(?int $docId = null): void
{
    if ($docId !== null && $docId > 0) {
        $doc = DB::one('SELECT * FROM {docs} WHERE id = ?', [$docId]);
        if ($doc) {
            @unlink(qn_static_path($doc));
        }
        return;
    }
    qn_rmdir(QN_DATA . '/pages');
    qn_mkdir(QN_DATA . '/pages');
}

function qn_rebuild_static(): array
{
    $stat = ['ok' => 0, 'skip' => 0, 'fail' => 0];
    if (!DB::ready() || (int) opt('static_cache', 1) !== 1) {
        return $stat;
    }
    qn_purge_static();
    $rows = DB::all('SELECT * FROM {docs}');
    foreach ($rows as $doc) {
        if ($doc['status'] !== 'public') {
            $stat['skip']++;
            continue;
        }
        if (qn_build_static($doc)) {
            $stat['ok']++;
        } else {
            $stat['fail']++;
        }
    }
    return $stat;
}

/** 浏览量 +1（同一会话 30 分钟内不重复计） */
function qn_hit(int $docId): int
{
    if ($docId <= 0) {
        return 0;
    }
    $seen = $_SESSION['qn_hit'] ?? [];
    if (isset($seen[$docId]) && time() - (int) $seen[$docId] < 1800) {
        return (int) DB::val('SELECT views FROM {docs} WHERE id = ?', [$docId]);
    }
    $_SESSION['qn_hit'][$docId] = time();
    DB::exec('UPDATE {docs} SET views = views + 1 WHERE id = ?', [$docId]);
    return (int) DB::val('SELECT views FROM {docs} WHERE id = ?', [$docId]);
}

/* --------------------------------------------------------------- 导航输出 */

function qn_nav_has(array $tree, string $slug): bool
{
    if ($slug === '') {
        return false;
    }
    foreach ($tree as $node) {
        if ($node['slug'] === $slug) {
            return true;
        }
        if (!empty($node['children']) && qn_nav_has($node['children'], $slug)) {
            return true;
        }
    }
    return false;
}

/** 递归输出侧边文档树（整合模式用） */
function qn_nav_html(array $tree, string $currentSlug = '', int $depth = 0): string
{
    if (!$tree) {
        return '';
    }
    $html = '<ul class="qn-nav qn-nav-' . $depth . '">';
    foreach ($tree as $node) {
        $active      = $currentSlug !== '' && $currentSlug === $node['slug'];
        $hasChildren = !empty($node['children']);
        $opened      = $hasChildren && ($active || qn_nav_has($node['children'], $currentSlug));
        $class       = 'qn-nav-item' . ($active ? ' active' : '') . ($opened ? ' open' : '');
        $html       .= '<li class="' . $class . '">';
        $html       .= '<a href="' . e(doc_url($node['slug'])) . '">' . e($node['title']) . '</a>';
        if ($hasChildren) {
            $html .= qn_nav_html($node['children'], $currentSlug, $depth + 1);
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

/** 页头的一级导航（整合模式用） */
function qn_top_nav_html(string $currentSlug = ''): string
{
    $html = '';
    foreach (qn_nav_tree() as $node) {
        $active = $currentSlug === $node['slug'] || qn_nav_has($node['children'], $currentSlug);
        $html  .= '<a class="qn-tnav-item' . ($active ? ' on' : '') . '" href="' . e(doc_url($node['slug'])) . '">'
            . e($node['title']) . '</a>';
    }
    return $html;
}

/** 文档链接列表（整站列表 / 搜索 / 标签页共用） */
function qn_doc_list_html(array $docs): string
{
    if (!$docs) {
        return '<p class="qn-muted">还没有已发布的文档。</p>';
    }
    $html = '<ul class="qn-doc-list">';
    foreach ($docs as $row) {
        $desc = isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : '';
        $html .= '<li><a href="' . e(doc_url($row)) . '">' . e($row['title']) . '</a>';
        if ($desc !== '') {
            $html .= '<span class="qn-doc-list-desc">' . e($desc) . '</span>';
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
}
