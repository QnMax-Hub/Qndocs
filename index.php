<?php
/**
 * Qndocs - 前台入口（所有文档页面最终都是这里的 HTML 输出 / 静态文件直出）
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_install();

if (!DB::ready()) {
    http_response_code(500);
    exit('数据库未连接');
}

$request   = trim((string) ($_GET['p'] ?? ''), "/ \t");
$slug      = $request;
if (preg_match('/^(.*)\.html$/i', $slug, $m)) {
    $slug = $m[1];
}
$slug = trim($slug, '/');

/* ---------------------------------------------------------- 浏览量接口 */

if ($slug === '__hit') {
    qn_json_ok(['views' => qn_hit((int) ($_GET['id'] ?? 0))]);
}

/* ---------------------------------------------------------- sitemap */

if ($slug === '__sitemap') {
    header('Content-Type: application/xml; charset=utf-8');
    $base  = qn_base_url();
    $xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $home  = qn_home_doc();
    if ($home) {
        $xml .= '  <url><loc>' . e($base . doc_url($home)) . '</loc><lastmod>' . e(date('Y-m-d', strtotime((string) $home['updated_at']))) . "</lastmod><priority>1.0</priority></url>\n";
    }
    foreach (qn_public_docs() as $row) {
        if ($row['status'] !== 'public' || (int) ($home['id'] ?? 0) === (int) $row['id']) {
            continue;
        }
        $xml .= '  <url><loc>' . e($base . doc_url($row)) . '</loc><lastmod>' . e(date('Y-m-d', strtotime((string) $row['updated_at']))) . "</lastmod><priority>0.7</priority></url>\n";
    }
    echo $xml . '</urlset>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

/* ---------------------------------------------------------- 路由 */

$mode      = 'doc';
$doc       = null;
$keyword   = '';
$results   = [];
$pwError   = '';
$statusCode = 200;
$tag        = null;
$docs       = [];
$totalDocs  = 0;
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = max(5, min(100, (int) opt('per_page', 20)));
$siblings   = ['prev' => null, 'next' => null];

if ($slug === '') {
    $doc = qn_home_doc();
    if (!$doc) {
        $mode = 'list';
    }
} elseif ($slug === '__list') {
    $mode = 'list';
} elseif ($slug === '__tag') {
    $mode = 'tag';
    $tag  = qn_find_tag(trim((string) ($_GET['t'] ?? '')));
    if (!$tag) {
        $mode = '404';
        $statusCode = 404;
    }
} elseif ($slug === '__search') {
    $mode    = 'search';
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $results = qn_search_docs($keyword);
} else {
    $doc = qn_find_doc($slug, false);
    if (!$doc) {
        $mode = '404';
        $statusCode = 404;
    }
}

/* ---------------------------------------------------------- 密码 / 私有 */

if ($doc && $doc['status'] === 'password') {
    $unlocked = !empty($_SESSION['qn_doc_pw'][(int) $doc['id']]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_password'])) {
        $input = (string) $_POST['doc_password'];
        $ok = ($doc['password'] !== '' && password_verify($input, (string) $doc['password']))
            || ($doc['password'] !== '' && hash_equals((string) $doc['password'], $input));
        if ($ok) {
            $_SESSION['qn_doc_pw'][(int) $doc['id']] = time();
            $unlocked = true;
        } else {
            $pwError = '访问密码不正确，请重新输入。';
        }
    }
    if (!$unlocked && !is_logged_in()) {
        $mode = 'password';
    }
}

if ($doc && $doc['status'] === 'private' && !is_logged_in()) {
    $mode = '403';
    $statusCode = 403;
    $doc = null;
}

if ($mode === '404' || $mode === '403') {
    http_response_code($statusCode);
}

/* 私有/密码页不参与静态缓存 */

/* ---------------------------------------------------------- 静态直出 */
/* 片段 / 纯正文 / 纯文本模式都不走静态缓存 */

// 纯文本模式：直接输出纯文本，便于程序或脚本抓取
// 只对公开文档生效——密码文档必须先通过密码校验（见下方动态渲染）
if ($mode === 'doc' && $doc && $doc['status'] === 'public' && qn_text_mode()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Qndocs: text');
    echo qn_doc_plain($doc);
    exit;
}

if ($mode === 'doc' && $doc && $doc['status'] === 'public' && !qn_fragment_mode() && !qn_bare_mode()) {
    $hit = qn_static_hit($doc);
    if ($hit !== null) {
        header('Cache-Control: public, max-age=60');
        header('X-Qndocs: static');
        readfile($hit);
        exit;
    }
}

/* ---------------------------------------------------------- 动态渲染 */

if ($mode === 'doc' && $doc) {
    if ($doc['status'] === 'public') {
        qn_hit((int) $doc['id']);
    }
    if (!empty($doc['parent_id'])) {
        $parent = qn_find_doc_id((int) $doc['parent_id']);
        if ($parent && $parent['status'] === 'password') {
            $_SESSION['qn_doc_pw'][(int) $parent['id']] = time();
        }
    }
}

if ($mode === 'list') {
    $totalDocs = qn_count_public_docs();
    $docs      = qn_public_docs($perPage, ($page - 1) * $perPage);
    if ($page > 1 && !$docs) {
        $page = 1;
        $docs = qn_public_docs($perPage, 0);
    }
} elseif ($mode === 'tag' && $tag) {
    $docs      = qn_docs_by_tag((string) $tag['slug']);
    $totalDocs = count($docs);
}

$canonical = $mode === 'doc' && $doc
    ? qn_base_url() . doc_url($doc)
    : ($mode === 'search' && $keyword !== ''
        ? qn_base_url() . qn_url('index.php?p=__search&q=' . rawurlencode($keyword))
        : qn_base_url() . qn_url(''));

$ctx = qn_page_context($doc, [
    'mode'      => $mode,
    'keyword'   => $keyword,
    'results'   => $results,
    'docs'      => $docs,
    'tag'       => $tag,
    'total'     => $totalDocs,
    'page'      => $page,
    'per_page'  => $perPage,
    'pw_error'  => $pwError,
    'canonical' => $canonical,
]);

// 纯正文模式：只输出 <article> 正文（不带样式，适合宿主页面自己引入样式）
if (qn_bare_mode()) {
    header('X-Qndocs: bare');
    echo '<article class="qn-doc" id="qnDoc">' . $ctx['body'] . '</article>';
    exit;
}

// 片段模式：只输出「样式 + 正文」，方便直接嵌进自己的软件界面
if (qn_fragment_mode()) {
    header('X-Qndocs: fragment');
    echo qn_render_fragment($ctx);
    exit;
}

$html = qn_render_page($ctx);

// 公开文档：顺手把这次渲染结果写入静态缓存（同一次请求内完成的缓存自愈）
if ($mode === 'doc' && $doc && $doc['status'] === 'public') {
    qn_write_static($doc, $html);
}

echo $html;
