<?php
/**
 * Qndocs - 文档接口（JSON）
 *
 * 给软件端调用，用来动态获取"有哪些文档、地址是什么"：
 *   /api.php            站点信息 + 文档列表 + 树形结构
 *   /api.php?flat=1     只要扁平列表（不要 tree 字段）
 *
 * 只返回「公开」状态的文档，可跨域读取（方便桌面/移动端软件直接调用）。
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_install();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Cache-Control: public, max-age=60');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!DB::ready()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => '数据库未连接'], JSON_UNESCAPED_UNICODE);
    exit;
}

$flatOnly = isset($_GET['flat']) && $_GET['flat'] !== '' && $_GET['flat'] !== '0';
$base     = qn_base_url();

$rows = DB::all("SELECT id, parent_id, title, slug, type, description, updated_at
                 FROM {docs} WHERE status = 'public' ORDER BY sort ASC, id ASC");

/** 把文档地址补成可直接使用的绝对地址，并附带两种嵌入形式 */
$makeUrls = function (array $row) use ($base) {
    $url      = doc_url($row);
    $absolute = $base . $url;
    $sep      = strpos($absolute, '?') === -1 ? '?' : '&';
    return [
        'url'          => $absolute,                    // 完整独立页面
        'fragment_url' => $absolute . $sep . 'fragment=1', // 样式 + 正文（推荐给 WebView）
        'bare_url'     => $absolute . $sep . 'bare=1',     // 只有正文，不带样式
        'text_url'     => $absolute . $sep . 'text=1',     // 纯文本（给脚本 / 终端用）
    ];
};

$docs = [];
foreach ($rows as $row) {
    $urls   = $makeUrls($row);
    $docs[] = array_merge([
        'id'          => (int) $row['id'],
        'parent_id'   => (int) $row['parent_id'],
        'title'       => (string) $row['title'],
        'slug'        => (string) $row['slug'],
        'description' => (string) $row['description'],
        'updated_at'  => (string) $row['updated_at'],
    ], $urls);
}

// 树形结构
$tree = [];
if (!$flatOnly) {
    $group = [];
    foreach ($docs as $doc) {
        $group[$doc['parent_id']][] = $doc;
    }
    $build = function ($parent) use (&$build, $group) {
        $out = [];
        foreach ($group[$parent] ?? [] as $node) {
            $children = $build($node['id']);
            if ($children) {
                $node['children'] = $children;
            }
            $out[] = $node;
        }
        return $out;
    };
    $tree = $build(0);
}

$payload = [
    'ok'    => true,
    'site'  => [
        'name'   => (string) opt('site_name', 'Qndocs'),
        'desc'   => (string) opt('site_desc', ''),
        'domain' => (string) opt('site_domain', ''),
        'base'   => $base,
    ],
    'count' => count($docs),
    'docs'  => $docs,
];
if (!$flatOnly) {
    $payload['tree'] = $tree;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
