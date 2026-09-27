<?php
/**
 * Qndocs - 引导文件
 */

define('QN_VERSION', '1.2.0');
define('QN_ROOT', rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'));
define('QN_INC', rtrim(str_replace('\\', '/', __DIR__), '/'));
define('QN_DATA', QN_ROOT . '/data');
define('QN_UPLOAD_DIR', QN_ROOT . '/uploads');
define('QN_CONFIG_FILE', QN_DATA . '/config.php');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
date_default_timezone_set('Asia/Shanghai');

require_once QN_INC . '/helpers.php';

$GLOBALS['QN_CONFIG'] = is_file(QN_CONFIG_FILE) ? (array) require QN_CONFIG_FILE : [];
$GLOBALS['QN_DEBUG'] = !empty($GLOBALS['QN_CONFIG']['debug']) || is_file(QN_DATA . '/debug.lock');

if ($GLOBALS['QN_DEBUG']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
}

/* 错误处理：全部写日志；致命错误时输出可读页面而不是白屏 -------------- */
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    qn_log('PHP(' . $severity . '): ' . $message . ' @ '
        . str_replace(str_replace('\\', '/', QN_ROOT), '', str_replace('\\', '/', (string) $file)) . ':' . $line);
    return false;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $file = str_replace(str_replace('\\', '/', QN_ROOT), '', str_replace('\\', '/', (string) $error['file']));
    qn_log('FATAL: ' . $error['message'] . ' @ ' . $file . ':' . $error['line']);
    if (qn_debug()) {
        return; // 调试模式：让 PHP 直接显示原始错误
    }
    qn_fatal_page((string) $error['message'], (string) $error['file'], (int) $error['line']);
});

/* 会话 ---------------------------------------------------------------- */
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = qn_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_name('QndocsSid');
    @session_start();
}

/* 数据库 -------------------------------------------------------------- */
if (is_installed()) {
    require_once QN_INC . '/db.php';
    require_once QN_INC . '/auth.php';

    $dbOk = DB::init((array) qn_config('db', []));
    if (!$dbOk) {
        if (!is_installing()) {
            $message = DB::error();
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
                . '<title>数据库连接失败 - Qndocs</title>'
                . '<style>body{font:15px/1.9 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8fb;color:#1f2937;'
                . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
                . '.box{background:#fff;border:1px solid #e5e7eb;border-left:4px solid #2563eb;border-radius:12px;'
                . 'padding:28px 32px;max-width:620px;box-shadow:0 12px 32px rgba(15,23,42,.08)}'
                . 'h1{font-size:18px;margin:0 0 10px}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:13px}'
                . 'pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:12px}</style>'
                . '</head><body><div class="box"><h1>数据库连接失败</h1>'
                . '<p>Qndocs 无法连接数据库，请检查配置文件 <code>' . e(str_replace(QN_ROOT, '', QN_CONFIG_FILE)) . '</code> 中的数据库信息。</p>'
                . '<pre>' . e($message) . '</pre>'
                . '<p>修改配置后刷新本页；也可以删除该配置文件重新运行安装向导。</p>'
                . '</div></body></html>';
            exit;
        }
    }
}

require_once QN_INC . '/markdown.php';
require_once QN_INC . '/render.php';
require_once QN_INC . '/auth.php';   // 仅包含函数定义，渲染层会用到 is_logged_in()
require_once QN_INC . '/schema.php'; // 数据表结构定义（安装向导与诊断工具共用）

/* 伪静态检测 ---------------------------------------------------------- */
$GLOBALS['QN_REWRITE_OK'] = false;
if (is_installed() && DB::ready()) {
    if ((int) opt('rewrite', 0) === 1) {
        if (qn_server_is_nginx()) {
            // nginx 不解析 .htaccess：必须由用户在设置中确认已配置重写规则
            $GLOBALS['QN_REWRITE_OK'] = (int) opt('rewrite_nginx', 0) === 1;
        } else {
            $GLOBALS['QN_REWRITE_OK'] = is_file(QN_ROOT . '/.htaccess');
        }
    }
}
