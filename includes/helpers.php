<?php
/**
 * Qndocs - 公共函数库
 */

/* ------------------------------------------------------------------ 配置 */

function qn_config_all(): array
{
    return isset($GLOBALS['QN_CONFIG']) && is_array($GLOBALS['QN_CONFIG']) ? $GLOBALS['QN_CONFIG'] : [];
}

function qn_config(string $key, $default = null)
{
    $all = qn_config_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function qn_debug(): bool
{
    return !empty($GLOBALS['QN_DEBUG']);
}

function is_installed(): bool
{
    if (!is_file(QN_CONFIG_FILE)) {
        return false;
    }
    $all = qn_config_all();
    return !empty($all['installed']);
}

function is_installing(): bool
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    return $script === 'install.php';
}

/** 未安装时强制跳转安装向导 */
function require_install(): void
{
    if (!is_installed() && !is_installing()) {
        $prefix = qn_seg() . '/';
        header('Location: ' . $prefix . 'install.php');
        echo '<!doctype html><meta charset="utf-8"><title>安装 Qndocs</title>'
            . '<p style="font:16px/1.8 system-ui;padding:40px">Qndocs 尚未安装，正在跳转安装向导… '
            . '<a href="' . e($prefix . 'install.php') . '">如果没有自动跳转请点这里</a></p>';
        exit;
    }
}

/* ------------------------------------------------------------------ 选项 */

function qn_option_defaults(): array
{
    return [
        'site_name'        => 'Qndocs',
        'site_desc'        => '轻量、纯粹的在线文档系统',
        'site_keywords'    => 'Qndocs,文档,知识库,在线文档',
        'site_domain'      => '',
        'site_logo'        => '',
        'site_footer'      => '',
        'site_icp'         => '',
        'home_doc_id'      => '0',
        'theme'            => 'linear-blue',
        'site_mode'        => 'standalone',
        'show_sidebar'     => '1',
        'static_cache'     => '1',
        'doc_width'        => '860',
        'css_vars'         => '',
        'css_custom'       => '',
        'head_code'        => '',
        'per_page'         => '20',
        'rewrite'          => '0',
        'rewrite_nginx'    => '0',
        'installed_at'     => '',
        'installed_version' => '',
    ];
}

function qn_load_options(): array
{
    $opts = qn_option_defaults();
    if (!is_installed() || !class_exists('DB') || !DB::ready()) {
        return $opts;
    }
    try {
        $rows = DB::all('SELECT k, v FROM {settings}');
        foreach ($rows as $row) {
            $opts[$row['k']] = $row['v'];
        }
    } catch (Throwable $e) {
        qn_log('load options failed: ' . $e->getMessage());
    }
    return $opts;
}

function opt(string $key, $default = null)
{
    if (!isset($GLOBALS['QN_OPTIONS']) || !is_array($GLOBALS['QN_OPTIONS'])) {
        $GLOBALS['QN_OPTIONS'] = qn_load_options();
    }
    if (array_key_exists($key, $GLOBALS['QN_OPTIONS'])) {
        return $GLOBALS['QN_OPTIONS'][$key];
    }
    $defaults = qn_option_defaults();
    return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
}

function set_opt(string $key, $value): void
{
    if (!isset($GLOBALS['QN_OPTIONS']) || !is_array($GLOBALS['QN_OPTIONS'])) {
        $GLOBALS['QN_OPTIONS'] = qn_load_options();
    }
    $GLOBALS['QN_OPTIONS'][$key] = (string) $value;
    DB::exec('REPLACE INTO {settings} (k, v) VALUES (?, ?)', [$key, (string) $value]);
}

/** 极简模式：URL 加 ?plain=1 时不加载任何样式与脚本，用于排查前端渲染问题 */
function qn_plain_mode(): bool
{
    return isset($_GET['plain']) && $_GET['plain'] !== '';
}

/** 片段模式：URL 加 ?fragment=1 时只输出「样式 + 正文」，便于直接嵌进自己的软件 */
function qn_fragment_mode(): bool
{
    return isset($_GET['fragment']) && $_GET['fragment'] !== '' && $_GET['fragment'] !== '0';
}

/** 纯正文模式：URL 加 ?bare=1 时只输出 <article> 正文（不带样式） */
function qn_bare_mode(): bool
{
    return isset($_GET['bare']) && $_GET['bare'] !== '' && $_GET['bare'] !== '0';
}

/** 纯文本模式：URL 加 ?text=1 时直接输出纯文本，便于程序/脚本抓取 */
function qn_text_mode(): bool
{
    return isset($_GET['text']) && $_GET['text'] !== '' && $_GET['text'] !== '0';
}

/* ------------------------------------------------------------------ 输出 */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qn_json($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qn_json_ok($data = [], string $message = 'ok'): void
{
    qn_json(['ok' => true, 'message' => $message, 'data' => $data]);
}

function qn_json_err(string $message, int $code = 400): void
{
    qn_json(['ok' => false, 'message' => $message], $code);
}

function redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    }
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . e($url) . '">';
    exit;
}

/* ------------------------------------------------------------------ 地址 */

function qn_base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $domain = (string) opt('site_domain', '');
    if ($domain !== '') {
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');
        if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
            // 本地调试（localhost / IP）时仍然使用当前主机
            $host = strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']));
            if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || strpos($host, '.test') !== false || strpos($host, '127.0.0.1') !== false) {
                $domain = $host;
            }
        }
        $scheme = qn_is_https() ? 'https' : 'http';
        $cached = $scheme . '://' . $domain;
        return $cached;
    }
    $scheme = qn_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $cached = $scheme . '://' . $host;
    return $cached;
}

function qn_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '';
    return strtolower((string) $proto) === 'https';
}

/**
 * 站点基准路径（子目录部署时为 /docs 这类前缀，根目录为空字符串）
 *
 * 关键：不能用 $_SERVER['SCRIPT_NAME'] 推导 —— 后台（/admin/xxx.php）生成
 * 静态页面时那样会得到 /admin，导致静态页内的链接全部错位。
 */
function qn_detect_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    // 1) 安装时写入的配置优先（最可靠）
    $config = isset($GLOBALS['QN_CONFIG']) && is_array($GLOBALS['QN_CONFIG']) ? $GLOBALS['QN_CONFIG'] : [];
    if (array_key_exists('base_path', $config)) {
        $configured = trim((string) $config['base_path']);
        $base = $configured === '' ? '' : '/' . trim($configured, '/');
        return $base;
    }

    // 2) 用站点根目录推导（与入口脚本无关）
    $docRoot = rtrim(str_replace('\\', '/', (string) (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '')), '/');
    if ($docRoot !== '' && stripos(QN_ROOT . '/', $docRoot . '/') === 0) {
        $base = rtrim(substr(QN_ROOT, strlen($docRoot)), '/');
        return $base;
    }

    // 3) 兜底：由当前脚本位置推导（去掉 /admin 后缀）
    $script = str_replace('\\', '/', (string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php'));
    $dir    = rtrim(dirname($script), '/');
    $dir    = (string) preg_replace('#/admin$#', '', $dir);
    $base   = ($dir === '' || $dir === '.' || $dir === '/') ? '' : $dir;
    return $base;
}

function qn_seg(): string
{
    return qn_detect_base_path();
}

/** 静态缓存标记：用于识别缓存是否由当前部署路径 / 版本 / 链接形式 / 输出模式生成 */
function qn_cache_marker(): string
{
    return '<!--qndocs:cache ' . qn_seg() . ' v' . QN_VERSION
        . ' r' . (qn_rewrite() ? '1' : '0')
        . ' m' . (opt('site_mode', 'standalone') === 'integrated' ? 'i' : 's') . '-->';
}

function qn_url(string $path = ''): string
{
    return qn_seg() . '/' . ltrim($path, '/');
}

function qn_asset(string $path): string
{
    // 带版本参数，升级后浏览器会自动重新拉取样式与脚本，避免继续用旧缓存
    return qn_url('assets/' . ltrim($path, '/')) . '?v=' . QN_VERSION;
}

function admin_url(string $path = ''): string
{
    return qn_seg() . '/admin/' . ltrim($path, '/');
}

/** 服务器是否为 nginx（nginx 不解析 .htaccess） */
function qn_server_is_nginx(): bool
{
    $server = strtolower((string) (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : ''));
    return strpos($server, 'nginx') !== false;
}

/** 前台路径重写是否可用 */
function qn_rewrite(): bool
{
    return (bool) opt('rewrite', 0) && !empty($GLOBALS['QN_REWRITE_OK']);
}

function doc_url($doc): string
{
    $slug = is_array($doc) ? (string) $doc['slug'] : (string) $doc;
    if ($slug === '') {
        return qn_url('index.php');
    }
    if (qn_rewrite()) {
        return qn_seg() . '/' . $slug . '.html';
    }
    return qn_url('index.php?p=' . rawurlencode($slug) . '.html');
}

/* ------------------------------------------------------------------ 表单 */

function csrf_token(): string
{
    if (empty($_SESSION['qn_csrf'])) {
        $_SESSION['qn_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['qn_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool
{
    $token = $token ?? ($_POST['_token'] ?? ($_GET['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    return is_string($token) && $token !== '' && !empty($_SESSION['qn_csrf']) && hash_equals((string) $_SESSION['qn_csrf'], $token);
}

function qn_require_csrf(): void
{
    if (!csrf_verify()) {
        if (qn_is_ajax()) {
            qn_json_err('会话已过期，请刷新页面后重试', 419);
        }
        http_response_code(419);
        exit('会话已过期，请返回刷新页面后重试。');
    }
}

function qn_is_ajax(): bool
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    return isset($_GET['_ajax']) || isset($_POST['_ajax']);
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function flash(?string $message = null, string $type = 'success')
{
    if ($message !== null) {
        $_SESSION['qn_flash'][] = ['msg' => $message, 'type' => $type];
        return null;
    }
    $list = $_SESSION['qn_flash'] ?? [];
    unset($_SESSION['qn_flash']);
    return $list;
}

function flash_render(): string
{
    $list = flash();
    if (!$list) {
        return '';
    }
    $html = '';
    foreach ($list as $item) {
        $type = in_array($item['type'], ['success', 'error', 'info', 'warn'], true) ? $item['type'] : 'info';
        $html .= '<div class="qn-toast qn-toast-' . $type . '">' . e($item['msg']) . '</div>';
    }
    return '<div class="qn-toasts">' . $html . '</div>';
}

/* ------------------------------------------------------------------ 工具 */

function qn_slugify(string $text, string $fallback = ''): string
{
    $text = trim($text);
    $text = preg_replace('/[\s\x{3000}]+/u', '-', $text);
    $text = preg_replace('/[^\p{L}\p{N}\-_.]/u', '', $text);
    $text = preg_replace('/-{2,}/', '-', $text);
    $text = trim($text, '-_.');
    $text = mb_substr($text, 0, 80, 'UTF-8');
    if ($text === '') {
        $text = $fallback;
    }
    if ($text === '') {
        $text = 'doc-' . date('YmdHis');
    }
    return $text;
}

function qn_text($html, int $limit = 160): string
{
    $text = strip_tags((string) $html);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') > $limit) {
        $text = mb_substr($text, 0, $limit, 'UTF-8') . '…';
    }
    return $text;
}

function qn_now(): string
{
    return date('Y-m-d H:i:s');
}

function qn_date(?string $value, string $format = 'Y-m-d H:i'): string
{
    if (empty($value) || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

function qn_time_ago(?string $value): string
{
    $ts = $value ? strtotime($value) : 0;
    if (!$ts) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 2592000) {
        return floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

function qn_bytes($bytes): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function qn_log(string $message): void
{
    $dir = QN_DATA . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/** 发生致命错误时输出一个可读的错误页（而不是白屏） */
function qn_fatal_page(string $message, string $file = '', int $line = 0): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    $where = '';
    if ($file !== '') {
        $where = str_replace('\\', '/', $file);
        $where = str_replace(str_replace('\\', '/', QN_ROOT), '', $where);
        if ($line > 0) {
            $where .= ':' . $line;
        }
    }

    $lower = strtolower($message);
    $hints = [];
    if (strpos($lower, 'undefined function') !== false
        || strpos($lower, 'undefined constant') !== false
        || strpos($lower, 'not found') !== false) {
        $hints[] = '程序文件可能没有完整上传，或者新旧版本文件混用（只替换了部分文件）。请按说明文件清单重新上传，并保留 data/config.php 不动。';
    }
    if (strpos($lower, 'permission denied') !== false
        || strpos($lower, 'failed to open stream') !== false
        || strpos($lower, 'mkdir') !== false
        || strpos($lower, 'file_put_contents') !== false) {
        $hints[] = '目录权限不足：请确认 data/、data/pages/、data/logs/、uploads/ 目录可写（Linux 一般 755，属主为运行 PHP 的用户）。';
    }
    if (strpos($lower, 'sqlstate') !== false || strpos($lower, 'pdo') !== false || strpos($lower, 'mysql') !== false) {
        $hints[] = '数据库连接或查询失败：请检查 data/config.php 中的数据库信息，以及数据库服务是否正常。';
    }
    if (strpos($lower, 'cannot re') !== false || strpos($lower, 'already in use') !== false) {
        $hints[] = '存在重复定义（同一文件被重复包含或上传不完整），建议重新完整上传程序文件。';
    }
    $hints[] = '如需更详细的错误信息：在 data/ 目录下新建一个空文件 debug.lock，或把 data/config.php 里的 debug 设为 true，然后刷新页面。';

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>Qndocs 运行时错误</title><style>'
        . ':root{--p:#2563eb;--line:#e5e7eb;--text:#1f2937;--muted:#6b7280}'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;background:#f6f8fb;color:var(--text);'
        . 'font:15px/1.8 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
        . '.box{background:#fff;border:1px solid var(--line);border-left:4px solid #dc2626;border-radius:12px;'
        . 'padding:26px 30px;max-width:780px;width:100%;box-shadow:0 14px 36px rgba(15,23,42,.08)}'
        . 'h1{font-size:18px;margin:0 0 6px}'
        . 'p{margin:8px 0;color:#374151}'
        . 'code,pre{font-family:ui-monospace,Consolas,monospace}'
        . 'pre{background:#0f172a;color:#e2e8f0;padding:14px 16px;border-radius:8px;overflow:auto;'
        . 'font-size:12.5px;white-space:pre-wrap;word-break:break-word;margin:10px 0}'
        . '.where{color:var(--muted);font-size:13px;margin:0 0 4px}'
        . 'ul{margin:12px 0 0;padding-left:20px}li{margin:6px 0;color:#374151;font-size:14px}'
        . '.tip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;'
        . 'font-size:13.5px;color:#1e40af;margin-top:18px}'
        . '</style></head><body><div class="box">'
        . '<h1>Qndocs 运行时错误</h1>'
        . ($where !== '' ? '<p class="where">位置：' . e($where) . '</p>' : '')
        . '<pre>' . e($message) . '</pre><ul>';
    foreach ($hints as $hint) {
        echo '<li>' . e($hint) . '</li>';
    }
    echo '</ul><div class="tip">错误已记录到 <code>data/logs/app.log</code>；后台「工具 → 运行日志」也能查看最近记录。</div>'
        . '</div></body></html>';
}

function qn_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? qn_rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function qn_mkdir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true);
}

/** 检查目录是否可写（不存在则尝试创建） */
function qn_writable(string $dir): bool
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return is_dir($dir) && is_writable($dir);
}

function qn_try_write(string $file, string $content): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    return @file_put_contents($file, $content) !== false;
}

/** 主题「线性蓝」的初始 CSS 变量 */
function qn_default_css_vars(): array
{
    return [
        'primary'       => '#2563eb',
        'primary-dark'  => '#1d4ed8',
        'primary-soft'  => '#e8f0fe',
        'text'          => '#1f2937',
        'text-muted'    => '#64748b',
        'bg'            => '#ffffff',
        'bg-soft'       => '#f6f8fb',
        'border'        => '#e5e7eb',
        'radius'        => '10px',
        'radius-sm'     => '6px',
        'line-height'   => '1.85',
        'font-size'     => '16px',
        'font-family'   => '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif',
        'content-width' => '860px',
        'code-bg'       => '#f7f9fc',
        'code-text'     => '#24314a',
    ];
}

function qn_css_vars(): array
{
    $raw = (string) opt('css_vars', '');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    // 空值一律忽略，回退到主题默认（否则会写出 --primary: ; 导致按钮白字透明底）
    $out = [];
    foreach ($data as $key => $value) {
        if (trim((string) $value) === '') {
            continue;
        }
        $out[$key] = $value;
    }
    return $out;
}

function qn_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** 保存一个上传文件，返回 ['ok'=>bool,'message'=>string,'data'=>array] */
function qn_save_upload(array $file): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => '没有接收到文件'];
    }
    $max = 10 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'message' => '文件超过 10MB 限制'];
    }
    $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'pdf', 'zip', 'rar', '7z',
              'txt', 'md', 'csv', 'xlsx', 'docx', 'pptx', 'mp4', 'mp3'];
    $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allow, true)) {
        return ['ok' => false, 'message' => '不支持的文件类型：' . $ext];
    }

    $sub = date('Ym');
    $dir = QN_UPLOAD_DIR . '/' . $sub;
    if (!qn_mkdir($dir)) {
        return ['ok' => false, 'message' => '上传目录不可写：uploads/' . $sub];
    }

    $safe   = qn_slugify((string) pathinfo((string) $file['name'], PATHINFO_FILENAME), 'file');
    $name   = $safe . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'message' => '文件保存失败，请检查 uploads 目录权限'];
    }
    @chmod($target, 0644);

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $target);
            finfo_close($finfo);
        }
    }
    if ($mime === '') {
        $mime = (string) ($file['type'] ?? '');
    }

    $url = qn_url('uploads/' . $sub . '/' . $name);
    $id  = DB::insert('files', [
        'name'       => (string) $file['name'],
        'path'       => 'uploads/' . $sub . '/' . $name,
        'url'        => $url,
        'size'       => (int) ($file['size'] ?? 0),
        'mime'       => $mime,
        'user_id'    => current_user_id(),
        'created_at' => qn_now(),
    ]);

    return [
        'ok'      => true,
        'message' => '上传成功',
        'data'    => ['id' => $id, 'url' => $url, 'name' => (string) $file['name'], 'is_image' => strpos($mime, 'image/') === 0],
    ];
}

function qn_server_info(): array
{
    return [
        'php'    => PHP_VERSION,
        'sapi'   => PHP_SAPI,
        'os'     => PHP_OS,
        'mysql'  => (class_exists('DB') && DB::ready()) ? (string) DB::val('SELECT VERSION()') : '—',
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? '—',
    ];
}
