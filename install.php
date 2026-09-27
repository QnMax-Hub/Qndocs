<?php
/**
 * Qndocs - 安装向导
 * 首次访问（data/config.php 不存在）时自动进入这里。
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once QN_INC . '/db.php';   // 安装阶段数据库类尚未被 bootstrap 加载

/* ------------------------------------------------------------------ 默认值 */

$DEFAULT = [
    'db'   => [
        'host'   => 'localhost',
        'port'   => '3306',
        'name'   => '',
        'user'   => '',
        'pass'   => '',
        'prefix' => 'qn_',
    ],
    'site' => [
        'name'     => 'Qndocs',
        'domain'   => '',
        'desc'     => '轻量、纯粹的在线文档系统',
        'theme'    => 'linear-blue',
        'username' => 'admin',
        'password' => '',
        'email'    => '',
    ],
];

$state  = isset($_SESSION['qn_install']) && is_array($_SESSION['qn_install']) ? $_SESSION['qn_install'] : [];
$step   = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$done   = null;

/* ------------------------------------------------------------------ 已安装 */

if (is_installed()) {
    install_page('安装向导', 4, '<div class="alert ok"><strong>Qndocs 已经安装完成。</strong><br>'
        . '如需重新安装，请先删除文件 <code>data/config.php</code>（数据库中的表会被复用，不会自动清空）。</div>'
        . '<div class="actions"><a class="btn" href="' . e(admin_url('')) . '">进入后台</a>'
        . '<a class="btn ghost" href="' . e(qn_url('')) . '">访问前台</a></div>');
    exit;
}

/* ------------------------------------------------------------------ 表单处理 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'db') {
        $db = [
            'host'   => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'port'   => trim((string) ($_POST['db_port'] ?? '3306')),
            'name'   => trim((string) ($_POST['db_name'] ?? '')),
            'user'   => trim((string) ($_POST['db_user'] ?? '')),
            'pass'   => (string) ($_POST['db_pass'] ?? ''),
            'prefix' => trim((string) ($_POST['db_prefix'] ?? 'qn_')),
        ];
        if ($db['name'] === '' || $db['user'] === '') {
            $errors[] = '数据库名与数据库用户名不能为空。';
        }
        if (!$errors) {
            [$ok, $message] = install_check_db($db);
            if ($ok) {
                $state['db'] = $db;
                $_SESSION['qn_install'] = $state;
                $_SESSION['qn_install_notice'] = $message;
                redirect('install.php?step=3');
            }
            $errors[] = $message;
        }
        $state['db'] = $db;
        $step = 2;
    } elseif ($action === 'site') {
        $site = [
            'name'     => trim((string) ($_POST['site_name'] ?? 'Qndocs')),
            'domain'   => trim((string) ($_POST['site_domain'] ?? '')),
            'desc'     => trim((string) ($_POST['site_desc'] ?? '')),
            'theme'    => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_POST['theme'] ?? 'linear-blue')),
            'username' => trim((string) ($_POST['admin_user'] ?? 'admin')),
            'password' => (string) ($_POST['admin_pass'] ?? ''),
            'email'    => trim((string) ($_POST['admin_email'] ?? '')),
        ];
        if ($site['name'] === '') {
            $errors[] = '站点名称不能为空。';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $site['username'])) {
            $errors[] = '管理员账号只能包含字母、数字、下划线、短横线与点，长度 3-32。';
        }
        if (mb_strlen($site['password'], 'UTF-8') < 6) {
            $errors[] = '管理员密码至少 6 位。';
        }
        if (!$errors) {
            $state['site'] = $site;
            $_SESSION['qn_install'] = $state;
            redirect('install.php?step=4');
        }
        $state['site'] = $site;
        $step = 3;
    } elseif ($action === 'install') {
        if (empty($state['db']) || empty($state['site'])) {
            $errors[] = '安装信息已失效，请重新填写。';
            $step = 2;
        } else {
            $result = install_run($state['db'], $state['site']);
            if ($result['ok']) {
                unset($_SESSION['qn_install'], $_SESSION['qn_install_notice']);
                $done = $result;
                $step = 4;
            } else {
                $errors[] = $result['message'];
                $step = 4;
            }
        }
    }
}

if ($step < 1 || $step > 4) {
    $step = 1;
}
if ($step === 3 && empty($state['db'])) {
    $step = 2;
}
if ($step === 4 && $done === null && (empty($state['db']) || empty($state['site']))) {
    $step = 1;
}

/* ------------------------------------------------------------------ 页面输出 */

$db   = array_merge($DEFAULT['db'], isset($state['db']) ? $state['db'] : []);
$site = array_merge($DEFAULT['site'], isset($state['site']) ? $state['site'] : []);

$errorHtml = '';
foreach ($errors as $error) {
    $errorHtml .= '<div class="alert err">' . e($error) . '</div>';
}

if ($done !== null) {
    /* ---------------- 完成 ---------------- */
    $logs = '';
    foreach ($done['log'] as $line) {
        $logs .= '<li>' . e($line) . '</li>';
    }
    $content = '<div class="alert ok"><strong>安装完成，共写入 ' . (int) $done['tables'] . ' 张数据表。</strong></div>'
        . '<ul class="logs">' . $logs . '</ul>'
        . '<div class="alert warn">为了安全，建议安装完成后删除服务器上的 <code>install.php</code> 文件。</div>'
        . '<div class="actions"><a class="btn" href="' . e(admin_url('login.php')) . '">进入后台登录</a>'
        . '<a class="btn ghost" href="' . e(qn_url('')) . '" target="_blank">访问前台</a></div>';
    install_page('安装完成', 4, $content);
    exit;
}

if ($step === 1) {
    /* ---------------- 环境检查 ---------------- */
    $need = [
        'PHP 版本 &ge; 7.4（推荐 8.2）' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO 扩展'                      => extension_loaded('pdo'),
        'PDO MySQL 驱动'                => extension_loaded('pdo_mysql'),
        'mbstring 扩展'                 => extension_loaded('mbstring'),
        'JSON 扩展'                     => extension_loaded('json'),
        'data 目录可写'                  => qn_writable(QN_DATA),
        'data/pages 目录可写'            => qn_writable(QN_DATA . '/pages'),
        'uploads 目录可写'               => qn_writable(QN_UPLOAD_DIR),
    ];
    $pass = !in_array(false, $need, true);

    $rows = '';
    foreach ($need as $label => $ok) {
        $rows .= '<tr><td>' . $label . '</td><td class="' . ($ok ? 'yes' : 'no') . '">'
            . ($ok ? '✓ 通过' : '✗ 不满足') . '</td></tr>';
    }

    $server = $_SERVER['SERVER_SOFTWARE'] ?? '未知';
    $content = '<h2>环境检查</h2>'
        . '<table class="tbl">' . $rows . '</table>'
        . '<p class="muted">服务器：' . e($server) . ' ｜ PHP ' . e(PHP_VERSION) . ' ｜ ' . e(PHP_OS) . '</p>'
        . $errorHtml
        . ($pass
            ? '<div class="actions"><a class="btn" href="install.php?step=2">开始安装</a></div>'
            : '<div class="alert err">请先解决上面标红的环境问题，然后刷新本页。</div>');
    install_page('环境检查', 1, $content);
    exit;
}

if ($step === 2) {
    /* ---------------- 数据库 ---------------- */
    $content = '<h2>数据库配置</h2>'
        . '<p class="muted">请填写空间商提供的 MySQL 信息。如果数据库不存在，向导会尝试自动创建。</p>'
        . $errorHtml
        . '<form method="post" action="install.php">'
        . '<input type="hidden" name="step" value="2"><input type="hidden" name="action" value="db">'
        . '<div class="grid">'
        . '<label>数据库主机<input type="text" name="db_host" value="' . e($db['host']) . '" required></label>'
        . '<label>端口<input type="text" name="db_port" value="' . e($db['port']) . '" required></label>'
        . '<label>数据库名<input type="text" name="db_name" value="' . e($db['name']) . '" required></label>'
        . '<label>数据库用户名<input type="text" name="db_user" value="' . e($db['user']) . '" required></label>'
        . '<label>数据库密码<input type="text" name="db_pass" value="' . e($db['pass']) . '"></label>'
        . '<label>表前缀<input type="text" name="db_prefix" value="' . e($db['prefix']) . '"></label>'
        . '</div>'
        . '<div class="actions"><button class="btn" type="submit">测试连接并继续</button>'
        . '<a class="btn ghost" href="install.php?step=1">上一步</a></div>'
        . '</form>';
    install_page('数据库配置', 2, $content);
    exit;
}

if ($step === 3) {
    /* ---------------- 站点信息 ---------------- */
    $notice = (string) ($_SESSION['qn_install_notice'] ?? '');
    unset($_SESSION['qn_install_notice']);
    $themes = qn_themes();
    $options = '';
    foreach ($themes as $key => $meta) {
        $selected = $key === ($site['theme'] ?? 'linear-blue') ? ' selected' : '';
        $options .= '<option value="' . e($key) . '"' . $selected . '>' . e($meta['title']) . '</option>';
    }
    if ($options === '') {
        $options = '<option value="linear-blue">linear-blue</option>';
    }

    $content = '<h2>站点与管理员</h2>'
        . ($notice !== '' ? '<div class="alert ok">' . e($notice) . '</div>' : '')
        . $errorHtml
        . '<form method="post" action="install.php">'
        . '<input type="hidden" name="step" value="3"><input type="hidden" name="action" value="site">'
        . '<div class="grid">'
        . '<label>站点名称<input type="text" name="site_name" value="' . e($site['name']) . '" required></label>'
        . '<label>站点域名<input type="text" name="site_domain" value="' . e($site['domain']) . '" placeholder="docs.example.com"></label>'
        . '<label>站点简介<input type="text" name="site_desc" value="' . e($site['desc']) . '"></label>'
        . '<label>前台主题<select name="theme">' . $options . '</select></label>'
        . '<label>管理员账号<input type="text" name="admin_user" value="' . e($site['username']) . '" required></label>'
        . '<label>管理员密码<input type="text" name="admin_pass" value="' . e($site['password']) . '" required></label>'
        . '<label>管理员邮箱（可选）<input type="email" name="admin_email" value="' . e($site['email']) . '"></label>'
        . '</div>'
        . '<div class="actions"><button class="btn" type="submit">下一步</button>'
        . '<a class="btn ghost" href="install.php?step=2">上一步</a></div>'
        . '</form>';
    install_page('站点与管理员', 3, $content);
    exit;
}

/* ---------------- 确认安装 ---------------- */
$content = '<h2>确认安装</h2>'
    . $errorHtml
    . '<table class="tbl">'
    . '<tr><td>数据库</td><td><code>' . e($db['user'] . '@' . $db['host'] . ':' . $db['port'] . '/' . $db['name']) . '</code>（表前缀 <code>' . e($db['prefix']) . '</code>）</td></tr>'
    . '<tr><td>站点名称</td><td>' . e($site['name']) . '</td></tr>'
    . '<tr><td>站点域名</td><td>' . e($site['domain']) . '</td></tr>'
    . '<tr><td>前台主题</td><td>' . e($site['theme']) . '（初始样式：线性蓝）</td></tr>'
    . '<tr><td>管理员</td><td>' . e($site['username']) . '</td></tr>'
    . '</table>'
    . '<p class="muted">点击下面的按钮将创建数据表并写入配置文件 <code>data/config.php</code>。</p>'
    . '<form method="post" action="install.php">'
    . '<input type="hidden" name="step" value="4"><input type="hidden" name="action" value="install">'
    . '<div class="actions"><button class="btn" type="submit">开始安装</button>'
    . '<a class="btn ghost" href="install.php?step=3">上一步</a></div>'
    . '</form>';
install_page('确认安装', 4, $content);

/* ================================================================== 函数 */

/** 测试数据库连接，返回 [bool, string] */
function install_check_db(array $db): array
{
    if (!preg_match('/^[A-Za-z0-9_\-\.]{1,64}$/', $db['name'])) {
        return [false, '数据库名包含非法字符。'];
    }
    try {
        $pdo = DB::make($db, false);
    } catch (Throwable $e) {
        return [false, '连接数据库服务器失败：' . $e->getMessage()];
    }
    $name = str_replace('`', '', $db['name']);
    try {
        $exists = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($name))->fetchColumn();
    } catch (Throwable $e) {
        return [false, '无法读取数据库列表：' . $e->getMessage()];
    }
    if (!$exists) {
        try {
            $pdo->exec('CREATE DATABASE `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            return [false, '数据库 `' . $name . '` 不存在且创建失败，请在空间面板中手动创建后再试。（' . $e->getMessage() . '）'];
        }
    }
    return [true, '数据库连接成功：' . $db['user'] . '@' . $db['host'] . ' / ' . $db['name']];
}

/** 执行安装，返回 ['ok'=>bool,'message'=>string,'log'=>[],'tables'=>int] */
function install_run(array $db, array $site): array
{
    $log = [];
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $db['prefix']);
    if ($prefix === '') {
        $prefix = 'qn_';
    }
    $db['prefix'] = $prefix;

    /* 1. 建库建表 */
    try {
        $pdo = DB::make($db, false);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . str_replace('`', '', $db['name']) . '`');
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '数据库连接或建库失败：' . $e->getMessage(), 'log' => $log, 'tables' => 0];
    }

    $created = 0;
    foreach (qn_schema() as $label => $definition) {
        try {
            $pdo->exec(qn_schema_create_sql($label, $definition, $prefix));
            $created++;
            $log[] = '数据表 ' . $prefix . $label . ' 就绪';
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '创建数据表 ' . $prefix . $label . ' 失败：' . $e->getMessage(), 'log' => $log, 'tables' => $created];
        }
    }

    /* 2. 写入配置文件 */
    $config = [
        'installed'    => true,
        'app'          => 'Qndocs',
        'version'      => QN_VERSION,
        'domain'       => $site['domain'],
        'base_path'    => qn_detect_base_path(),
        'debug'        => false,
        'db'           => [
            'host'    => $db['host'],
            'port'    => (int) $db['port'],
            'name'    => $db['name'],
            'user'    => $db['user'],
            'pass'    => $db['pass'],
            'charset' => 'utf8mb4',
            'prefix'  => $prefix,
        ],
        'hash_key'     => bin2hex(random_bytes(16)),
        'installed_at' => date('Y-m-d H:i:s'),
    ];
    $body = "<?php\n/**\n * Qndocs 配置文件（由安装向导于 " . date('Y-m-d H:i:s') . " 生成）\n */\n\nreturn "
        . var_export($config, true) . ";\n";
    if (!qn_try_write(QN_CONFIG_FILE, $body)) {
        return ['ok' => false, 'message' => '无法写入配置文件 data/config.php，请检查 data 目录权限。', 'log' => $log, 'tables' => $created];
    }
    $log[] = '配置文件 data/config.php 写入成功';

    /* 3. 初始化连接与后台环境 */
    $GLOBALS['QN_CONFIG'] = $config;
    if (!DB::init($config['db'])) {
        return ['ok' => false, 'message' => '数据库连接失败：' . DB::error(), 'log' => $log, 'tables' => $created];
    }

    /* 4. 管理员账号 */
    try {
        $exists = DB::val('SELECT id FROM {users} WHERE username = ?', [$site['username']]);
        $data   = [
            'password'   => password_hash($site['password'], PASSWORD_DEFAULT),
            'nickname'   => '管理员',
            'email'      => $site['email'],
            'role'       => 'admin',
            'status'     => 1,
        ];
        if ($exists) {
            DB::update('users', $data, 'id = ?', [(int) $exists]);
            $log[] = '管理员账号 ' . $site['username'] . ' 已更新';
        } else {
            $data['username']   = $site['username'];
            $data['created_at'] = qn_now();
            DB::insert('users', $data);
            $log[] = '管理员账号 ' . $site['username'] . ' 创建成功';
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '创建管理员失败：' . $e->getMessage(), 'log' => $log, 'tables' => $created];
    }

    /* 5. 站点选项 */
    $options = [
        'site_name'         => $site['name'],
        'site_desc'         => $site['desc'],
        'site_keywords'     => $site['name'] . ',文档,知识库',
        'site_domain'       => $site['domain'],
        'site_footer'       => '© ' . date('Y') . ' ' . $site['name'],
        'site_icp'          => '',
        'theme'             => $site['theme'] !== '' ? $site['theme'] : 'linear-blue',
        'site_mode'         => 'standalone',
        'show_sidebar'      => '1',
        'static_cache'      => '1',
        'doc_width'         => '860',
        'css_vars'          => json_encode(qn_default_css_vars(), JSON_UNESCAPED_UNICODE),
        'css_custom'        => '',
        'head_code'         => '',
        'per_page'          => '20',
        'rewrite'           => qn_server_is_nginx() ? '0' : '1',
        'rewrite_nginx'     => '0',
        'installed_at'      => qn_now(),
        'installed_version' => QN_VERSION,
        'home_doc_id'       => '0',
    ];
    try {
        foreach ($options as $key => $value) {
            DB::exec('REPLACE INTO {settings} (k, v) VALUES (?, ?)', [$key, (string) $value]);
        }
        $GLOBALS['QN_OPTIONS'] = qn_load_options();
        $log[] = '站点配置写入成功';
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '写入站点配置失败：' . $e->getMessage(), 'log' => $log, 'tables' => $created];
    }

    /* 6. 示例文档 */
    try {
        $homeId = install_seed_docs((int) DB::val('SELECT id FROM {users} ORDER BY id ASC LIMIT 1'));
        set_opt('home_doc_id', (string) $homeId);
        $log[] = '示例文档创建成功（首页文档 ID：' . $homeId . '）';
    } catch (Throwable $e) {
        $log[] = '示例文档创建失败（可忽略）：' . $e->getMessage();
    }

    /* 7. 服务器规则文件 */
    install_write_server_files($db['prefix']);

    /* 8. 生成静态页面 */
    try {
        $stat = qn_rebuild_static();
        $log[] = '生成静态 HTML 页面 ' . $stat['ok'] . ' 个';
    } catch (Throwable $e) {
        $log[] = '静态页面生成失败（可在后台重新生成）：' . $e->getMessage();
    }

    return ['ok' => true, 'message' => '安装完成', 'log' => $log, 'tables' => $created];
}

/** 示例文档，返回首页文档 ID */
function install_seed_docs(int $userId): int
{
    $now  = qn_now();
    $home = DB::one('SELECT id FROM {docs} WHERE slug = ?', ['home']);
    if ($home) {
        return (int) $home['id'];
    }
    $homeId = DB::insert('docs', [
        'parent_id'  => 0,
        'title'      => '欢迎使用 Qndocs',
        'slug'       => 'home',
        'type'       => 'html',
        'content'    => install_demo_home(),
        'description' => 'Qndocs 是一个轻量、纯粹的在线文档系统，文档内容本质上就是一个 HTML 文件，样式由后台统一管理。',
        'keywords'   => 'Qndocs,在线文档,HTML,知识库',
        'sort'       => 0,
        'status'     => 'public',
        'password'   => '',
        'is_home'    => 1,
        'in_nav'     => 1,
        'views'      => 0,
        'author_id'  => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::insert('docs', [
        'parent_id'  => $homeId,
        'title'      => '编辑指南',
        'slug'       => 'editor-guide',
        'type'       => 'html',
        'content'    => install_demo_guide(),
        'description' => '用几分钟了解 Qndocs 的文档结构、发布流程与常用操作。',
        'keywords'   => '快速开始,Qndocs',
        'sort'       => 10,
        'status'     => 'public',
        'password'   => '',
        'is_home'    => 0,
        'in_nav'     => 1,
        'views'      => 0,
        'author_id'  => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::insert('docs', [
        'parent_id'  => $homeId,
        'title'      => '后台使用指南',
        'slug'       => 'admin-guide',
        'type'       => 'html',
        'content'    => install_demo_admin(),
        'description' => '文档管理、编辑器、样式中心、站点设置的使用说明。',
        'keywords'   => '后台,操作指南',
        'sort'       => 20,
        'status'     => 'public',
        'password'   => '',
        'is_home'    => 0,
        'in_nav'     => 1,
        'views'      => 0,
        'author_id'  => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // 纯文本文档示例：编辑器左上角把内容类型切到「纯文本」即可这样写
    DB::insert('docs', [
        'parent_id'  => 0,
        'title'      => '纯文本示例（Nginx 配置）',
        'slug'       => 'config-sample',
        'type'       => 'text',
        'content'    => install_demo_text(),
        'description' => '纯文本文档示例：内容是原样保存的文本，前台用 ?text=1 可直接取回原文。',
        'keywords'   => '纯文本,配置文件,nginx',
        'sort'       => 30,
        'status'     => 'public',
        'password'   => '',
        'is_home'    => 0,
        'in_nav'     => 1,
        'views'      => 0,
        'author_id'  => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $homeId;
}

function install_demo_home(): string
{
    return <<<'HTML'
<section class="qn-hero">
  <span class="qn-badge">Qndocs 1.0</span>
  <h1>示例文档</h1>
  <p class="qn-hero-desc">你现在看到的这一页，就是一篇<strong>独立的 HTML 文档</strong>：没有页头、页脚、侧栏和导航，可以直接嵌进你的软件（WebView / iframe），也可以单独用浏览器打开。</p>
</section>

<h2>四种取用方式</h2>
<table>
  <thead><tr><th>方式</th><th>地址</th><th>输出</th></tr></thead>
  <tbody>
    <tr><td>独立页面</td><td><code>/home.html</code></td><td>完整 HTML（含样式），可直接给 WebView 用</td></tr>
    <tr><td>片段模式</td><td><code>/home.html?fragment=1</code></td><td>只有 <code>&lt;style&gt;</code> + 正文，方便塞进自己的界面</td></tr>
    <tr><td>纯正文</td><td><code>/home.html?bare=1</code></td><td>只有正文，宿主页面已经引过样式时用</td></tr>
    <tr><td>纯文本</td><td><code>/home.html?text=1</code></td><td>去掉全部标签的纯文本，给脚本 / 终端直接抓</td></tr>
  </tbody>
</table>

<h2>两种输出模式</h2>
<p>后台「站点设置 → 输出模式」可随时切换，两种模式共用同一批文档与同一套样式：</p>
<ul>
  <li><strong>独立文档</strong>（默认）：就是现在这一页的样子，没有页头页脚，适合嵌进软件；</li>
  <li><strong>整合文档站</strong>：加上页头、侧边文档树、右栏本页目录、面包屑、上一篇/下一篇与页脚，适合用浏览器当一整套文档站浏览。</li>
</ul>

<h2>常用排版</h2>
<p>正文支持<strong>加粗</strong>、<em>斜体</em>、<u>下划线</u>、<a href="#">链接</a> 与 <code>行内代码</code>，这些都是用工具栏直接排出来的。</p>
<ul>
  <li>无序列表项</li>
  <li>第二项</li>
</ul>
<ol>
  <li>有序列表项</li>
  <li>第二项</li>
</ol>

<blockquote><p>引用块适合放提示与注意事项。</p></blockquote>

<pre><code>// 代码块
Qndocs：后台写内容，前台输出干净 HTML。</code></pre>

<h2>编辑与样式</h2>
<ol>
  <li>进入 <a href="/admin/">后台</a>，用安装时设置的账号登录；</li>
  <li>在「文档管理」里新建文档，用<strong>富文本工具栏</strong>直接排版；</li>
  <li>在「样式中心」调整主色、字号、圆角、内容宽度，保存后所有文档立即生效。</li>
</ol>

<div class="qn-alert">所有样式都限定在 <code>.qn-doc</code> 作用域内，因此把片段嵌到你自己的软件里，不会影响软件本身的界面样式。</div>
HTML;
}

function install_demo_guide(): string
{
    return <<<'HTML'
<h2>编辑器怎么用</h2>
<p>编辑区就是最终效果（所见即所得），工具栏从左到右依次是：</p>
<ul>
  <li><strong>段落格式</strong>：正文 / 标题 1-4</li>
  <li><strong>文字样式</strong>：加粗、斜体、下划线、删除线</li>
  <li><strong>列表与块</strong>：无序列表、有序列表、引用、代码块</li>
  <li><strong>对齐</strong>：左 / 中 / 右</li>
  <li><strong>颜色</strong>：默认色、蓝、红、绿</li>
  <li><strong>插入</strong>：链接、图片、表格、分割线</li>
  <li><strong>其它</strong>：清除格式、撤销、重做、HTML 源码、全屏</li>
</ul>

<h3>几个实用技巧</h3>
<table>
  <thead><tr><th>操作</th><th>说明</th></tr></thead>
  <tbody>
    <tr><td>Ctrl + S</td><td>保存并发布</td></tr>
    <tr><td>Ctrl + Enter</td><td>刷新右侧预览</td></tr>
    <tr><td>直接拖图片进编辑区</td><td>自动上传并插入</td></tr>
    <tr><td>从 Word / 网页粘贴</td><td>自动清理多余标签与样式</td></tr>
    <tr><td>「源码」按钮</td><td>需要精细控制时直接改 HTML</td></tr>
  </tbody>
</table>

<div class="qn-alert">想嵌进软件？用 <code>/文档slug.html?fragment=1</code> 只取「样式 + 正文」。</div>
HTML;
}

function install_demo_admin(): string
{
    return <<<'HTML'
<h2>后台功能一览</h2>
<table>
  <thead><tr><th>模块</th><th>说明</th></tr></thead>
  <tbody>
    <tr><td>仪表盘</td><td>文档数量、浏览量、最近修改、运行环境</td></tr>
    <tr><td>文档管理</td><td>层级目录、排序、状态切换、复制、删除、设为首页</td></tr>
    <tr><td>编辑器</td><td>富文本排版、图片上传、站内链接、HTML 源码、实时预览</td></tr>
    <tr><td>样式中心</td><td>CSS 变量统一调整、配色预设、自定义 CSS、实时预览</td></tr>
    <tr><td>媒体库</td><td>上传、复制地址、删除</td></tr>
    <tr><td>修订历史</td><td>每次保存留档，可对比并一键回滚</td></tr>
    <tr><td>用户管理</td><td>多成员、角色、禁用、重置密码</td></tr>
    <tr><td>站点设置</td><td>站点名、域名、SEO、伪静态、静态缓存、自定义 head</td></tr>
    <tr><td>工具</td><td>重建静态页、清缓存、SQL 备份、运行日志</td></tr>
  </tbody>
</table>

<h3>访问控制</h3>
<ol>
  <li><strong>公开</strong>：任何人可访问，并生成静态页</li>
  <li><strong>密码访问</strong>：需要输入访问密码</li>
  <li><strong>私有</strong>：仅登录后台的成员可见</li>
  <li><strong>草稿</strong>：前台不可见，仅后台预览</li>
</ol>

<h3>关于静态页面</h3>
<p>每篇公开文档都会在 <code>data/pages/</code> 下生成同名 <code>.html</code>，前台优先直接输出，速度快；文档更新或样式变更时会自动重新生成。</p>
HTML;
}

/** 纯文本文档示例（type = text：内容原样保存，缩进与空行都不动） */
function install_demo_text(): string
{
    return <<<'TXT'
# Qndocs 反向代理示例（纯文本文档）
# 这类文档的内容会原样保存：缩进、空行、符号都不会被改动，
# 适合放配置文件、命令、日志片段、许可证文本等。

server {
    listen 80;
    server_name doc.example.com;
    root /www/wwwroot/doc.example.com;

    location / {
        try_files $uri $uri/ /index.php?p=$uri&$args;
    }
}

# 前台取用方式（同一篇文档，四种形式随取随用）：
#   独立整页   http://doc.example.com/config-sample.html
#   样式+正文  http://doc.example.com/config-sample.html?fragment=1
#   只有正文   http://doc.example.com/config-sample.html?bare=1
#   纯文本     http://doc.example.com/config-sample.html?text=1
TXT;
}

/** 写入 .htaccess 等服务器规则文件 */
function install_write_server_files(string $prefix): void{
    $root = <<<'HT'
# Qndocs 伪静态规则（Apache）
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)\.html$ index.php?p=$1.html [L,QSA]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^search$ index.php?p=__search [L,QSA]
</IfModule>

DirectoryIndex index.php index.html
Options -Indexes

<FilesMatch "\.(sql|log|md|json)$">
  Require all denied
</FilesMatch>
HT;

    qn_try_write(QN_ROOT . '/.htaccess', $root);
    qn_try_write(QN_DATA . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    qn_try_write(QN_DATA . '/index.html', '');
    qn_try_write(QN_ROOT . '/admin/.htaccess', "DirectoryIndex index.php\nOptions -Indexes\n");
    qn_try_write(QN_DATA . '/pages/index.html', '');

    // 上传目录：禁止执行脚本，只对外提供静态文件
    qn_try_write(QN_ROOT . '/uploads/.htaccess', "Options -Indexes\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
        . "<FilesMatch \"\\.(php|phtml|php[0-9]?|phar|cgi|pl|py|asp|aspx|jsp|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    qn_try_write(QN_ROOT . '/uploads/index.html', '');
}

/* ------------------------------------------------------------------ 界面 */

function install_page(string $title, int $step, string $content): void
{
    $steps = [1 => '环境检查', 2 => '数据库', 3 => '站点信息', 4 => '完成'];
    $bar   = '';
    foreach ($steps as $index => $label) {
        $class = $index === $step ? 'on' : ($index < $step ? 'done' : '');
        $bar .= '<li class="' . $class . '"><span>' . $index . '</span>' . e($label) . '</li>';
    }
    ?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> - Qndocs 安装向导</title>
<style>
:root{--p:#2563eb;--pd:#1d4ed8;--ps:#e8f0fe;--text:#1f2937;--muted:#64748b;--line:#e5e7eb;--bg:#f6f8fb;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.75 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
a{color:var(--p);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:820px;margin:0 auto;padding:48px 20px 60px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.logo{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--p),#60a5fa);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;letter-spacing:.5px}
.brand h1{font-size:19px;margin:0;font-weight:600}
.brand small{display:block;color:var(--muted);font-size:12px;font-weight:400}
.steps{display:flex;gap:8px;list-style:none;padding:0;margin:0 0 20px}
.steps li{flex:1;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);border-top:2px solid var(--line);padding-top:12px}
.steps li span{width:22px;height:22px;border-radius:50%;background:#eef2f7;color:var(--muted);display:flex;align-items:center;justify-content:center;font-size:12px}
.steps li.on{color:var(--p);border-color:var(--p);font-weight:600}
.steps li.on span{background:var(--p);color:#fff}
.steps li.done{color:#16a34a;border-color:#16a34a}
.steps li.done span{background:#16a34a;color:#fff}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:30px 32px;box-shadow:0 10px 30px rgba(15,23,42,.05)}
h2{font-size:17px;margin:0 0 6px;font-weight:600}
h2+p{margin-top:0}
.muted{color:var(--muted);font-size:13px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0 4px}
label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#374151}
input,select{padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;background:#fff;color:var(--text)}
input:focus,select:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.tbl{width:100%;border-collapse:collapse;margin:18px 0;font-size:14px}
.tbl td{border-bottom:1px solid var(--line);padding:9px 4px}
.tbl tr:last-child td{border-bottom:0}
.tbl td:last-child{text-align:right;color:#374151}
.yes{color:#16a34a;font-weight:600}
.no{color:#dc2626;font-weight:600}
.actions{display:flex;gap:12px;align-items:center;margin-top:24px;flex-wrap:wrap}
.btn{display:inline-block;background:var(--p);border:1px solid var(--p);color:#fff;padding:10px 22px;border-radius:8px;font-size:14px;cursor:pointer;font-family:inherit;font-weight:500}
.btn:hover{background:var(--pd);border-color:var(--pd);text-decoration:none}
.btn.ghost{background:#fff;color:#374151;border-color:var(--line)}
.btn.ghost:hover{background:#f8fafc;color:var(--p);border-color:#c7d2fe;text-decoration:none}
.alert{border-radius:10px;padding:12px 16px;font-size:14px;margin:16px 0;border:1px solid transparent}
.alert.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.alert.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
.logs{margin:10px 0;padding-left:20px;color:#374151;font-size:13px}
code{background:#f1f5f9;padding:1px 6px;border-radius:5px;font-size:13px;font-family:ui-monospace,Consolas,monospace}
.foot{text-align:center;color:#94a3b8;font-size:12px;margin-top:22px}
@media (max-width:640px){.grid{grid-template-columns:1fr}.steps li{font-size:12px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="logo">QN</div>
    <h1><?= e((string) opt('site_name', 'Qndocs')) ?><small>轻量在线文档系统 · 安装向导 v<?= e(QN_VERSION) ?></small></h1>
  </div>
  <ul class="steps"><?= $bar ?></ul>
  <div class="card"><?= $content ?></div>
  <div class="foot">Qndocs · PHP <?= e(PHP_VERSION) ?> · 安装完成后请删除 install.php</div>
</div>
</body>
</html><?php
}
