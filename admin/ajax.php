<?php
/**
 * Qndocs - 后台 AJAX 接口（媒体库 / 上传 / 快捷操作）
 */

require_once __DIR__ . '/_common.php';

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if (!csrf_verify()) {
    qn_json_err('会话已过期，请刷新页面后重试。', 419);
}

/* ---------------------------------------------------------- 媒体列表 */

if ($action === 'media_list') {
    if (!is_dir(QN_UPLOAD_DIR)) {
        qn_mkdir(QN_UPLOAD_DIR);
    }
    $files = DB::all('SELECT * FROM {files} ORDER BY id DESC LIMIT 120');
    $html  = '';
    foreach ($files as $file) {
        $isImage = strpos((string) $file['mime'], 'image/') === 0;
        $html   .= '<figure class="ad-media-item" data-url="' . e($file['url']) . '" data-name="' . e($file['name']) . '">';
        $html   .= $isImage
            ? '<img src="' . e($file['url']) . '" alt="" loading="lazy">'
            : '<span class="ad-file-ext">' . e(strtoupper(pathinfo((string) $file['name'], PATHINFO_EXTENSION))) . '</span>';
        $html   .= '<figcaption>' . e($file['name']) . '<em>' . e(qn_bytes($file['size'])) . '</em></figcaption>';
        $html   .= '<span class="ad-media-ops">';
        $html   .= '<button type="button" class="ad-mini" data-pick="' . e($file['url']) . '">插入</button>';
        $html   .= '<button type="button" class="ad-mini danger" data-del="' . (int) $file['id'] . '">删除</button>';
        $html   .= '</span>';
        $html   .= '</figure>';
    }
    if ($html === '') {
        $html = '<p class="ad-muted">媒体库还是空的，上传一张图片试试。</p>';
    }
    qn_json_ok(['html' => $html, 'count' => count($files)]);
}

/* ---------------------------------------------------------- 上传 */

if ($action === 'upload') {
    $result = qn_save_upload(isset($_FILES['file']) ? $_FILES['file'] : []);
    if (!$result['ok']) {
        qn_json_err($result['message']);
    }
    qn_json_ok($result['data'], $result['message']);
}

/* ---------------------------------------------------------- 删除媒体 */

if ($action === 'media_delete') {
    $id   = (int) ($_POST['id'] ?? 0);
    $file = DB::one('SELECT * FROM {files} WHERE id = ?', [$id]);
    if (!$file) {
        qn_json_err('文件记录不存在。');
    }
    $path = QN_ROOT . '/' . $file['path'];
    if (is_file($path)) {
        @unlink($path);
    }
    DB::delete('files', 'id = ?', [$id]);
    qn_json_ok([], '已删除');
}

/* ---------------------------------------------------------- 文档快查（用于插入内部链接） */

if ($action === 'doc_search') {
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $rows    = $keyword === '' ? DB::all("SELECT id,title,slug FROM {docs} WHERE status <> 'draft' ORDER BY updated_at DESC LIMIT 20")
        : qn_search_docs($keyword, 20);
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['title' => $row['title'], 'url' => doc_url($row)];
    }
    qn_json_ok(['items' => $out]);
}

/* ---------------------------------------------------------- 统计（仪表盘刷新） */

if ($action === 'stats') {
    qn_json_ok([
        'docs'    => (int) DB::val('SELECT COUNT(*) FROM {docs}'),
        'views'   => (int) DB::val('SELECT COALESCE(SUM(views),0) FROM {docs}'),
        'static'  => count(glob(QN_DATA . '/pages/*.html') ?: []),
        'time'    => qn_now(),
    ]);
}

qn_json_err('未知的接口：' . $action, 404);
