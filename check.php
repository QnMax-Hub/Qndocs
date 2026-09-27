<?php
/**
 * Qndocs - 诊断与修复
 *
 * 后台打不开（白屏/500）时使用这里：
 *   · 只需要 data/config.php，不依赖任何业务数据表
 *   · 可检查数据表是否完整，并一键补建缺失的表与字段
 *
 * 访问方式（二选一）：
 *   1) 已登录的管理员直接访问 /check.php
 *   2) 用 data/config.php 里的 hash_key 前 12 位：/check.php?key=xxxxxxxxxxxx
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_install();

$expected = (string) qn_config('hash_key', '');
$key      = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
$byKey    = $expected !== '' && $key !== '' && hash_equals(substr($expected, 0, 12), $key);
$isAdmin  = is_logged_in() && is_admin();
$granted  = $byKey || $isAdmin;

$messages = [];
$logLines = [];
$status   = [];
$dbInfo   = ['ok' => false, 'message' => ''];

/* ------------------------------------------------------------------ 处理 */

if ($granted && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'repair') {
    if (!$byKey && !csrf_verify()) {
        $messages[] = ['err', '会话已过期，请刷新页面后重试。'];
    } elseif (!DB::ready()) {
        $messages[] = ['err', '数据库未连接，无法修复。请先检查 data/config.php。'];
    } else {
        try {
            foreach (qn_schema_repair((string) DB::prefix()) as $line) {
                $messages[] = [strpos($line, '失败') === false && strpos($line, '无法自动补加') === false ? 'ok' : 'err', $line];
            }
        } catch (Throwable $e) {
            $messages[] = ['err', '修复过程出错：' . $e->getMessage()];
        }
    }
}

/* ------------------------------------------------------------------ 采集 */

if ($granted) {
    if (DB::ready()) {
        $dbInfo = ['ok' => true, 'message' => '连接正常'];
        try {
            $status = qn_schema_status((string) DB::prefix());
        } catch (Throwable $e) {
            $dbInfo = ['ok' => false, 'message' => $e->getMessage()];
        }
    } else {
        $dbInfo = ['ok' => false, 'message' => DB::error() !== '' ? DB::error() : '数据库未连接'];
    }

    $logFile = QN_DATA . '/logs/app.log';
    if (is_file($logFile)) {
        $raw = trim((string) file_get_contents($logFile));
        if ($raw !== '') {
            $logLines = array_slice(preg_split('/\r\n|\r|\n/', $raw), -40);
        }
    }
}

$env = [
    'PHP 版本'        => PHP_VERSION,
    '服务器'          => (string) ($_SERVER['SERVER_SOFTWARE'] ?? '未知'),
    'MySQL 版本'      => DB::ready() ? (string) DB::val('SELECT VERSION()') : '未连接',
    'Qndocs 版本'     => QN_VERSION,
    '数据表前缀'      => (string) DB::prefix(),
    '站点域名'        => (string) opt('site_domain', ''),
    '调试模式'        => qn_debug() ? '已开启' : '关闭',
];

$dirs = [
    'data'         => QN_DATA,
    'data/pages'   => QN_DATA . '/pages',
    'data/logs'    => QN_DATA . '/logs',
    'uploads'      => QN_UPLOAD_DIR,
];

$broken = [];
foreach ($status as $table => $info) {
    if (!$info['exists'] || $info['missing']) {
        $broken[] = $table;
    }
}

?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>诊断与修复 · Qndocs</title>
<style>
:root{--p:#2563eb;--line:#e5e7eb;--text:#1f2937;--muted:#6b7280;--ok:#16a34a;--err:#dc2626}
*{box-sizing:border-box}
body{margin:0;background:#f6f8fb;color:var(--text);
 font:15px/1.75 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:940px;margin:0 auto;padding:36px 20px 60px}
h1{font-size:20px;margin:0 0 6px}
h2{font-size:15px;margin:0;padding:14px 20px;border-bottom:1px solid var(--line)}
.lead{color:var(--muted);font-size:13.5px;margin:0 0 22px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:18px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.04)}
.bd{padding:16px 20px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left}
th{background:#fbfcfe;font-weight:600;color:#4b5563;font-size:12.5px}
tr:last-child td{border-bottom:0}
code,pre{font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px}
pre{background:#0f172a;color:#dbe4f0;padding:12px 14px;border-radius:8px;overflow:auto;max-height:300px;white-space:pre-wrap;word-break:break-all}
.kv{list-style:none;margin:0;padding:0}
.kv li{display:flex;justify-content:space-between;gap:14px;padding:6px 0;border-bottom:1px dashed var(--line);font-size:13.5px}
.kv li:last-child{border-bottom:0}
.kv span{color:var(--muted)}
.kv b{font-weight:500;text-align:right;word-break:break-all}
.tag{display:inline-block;font-size:11.5px;border-radius:999px;padding:1px 9px;border:1px solid transparent}
.tag.ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.tag.err{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.tag.warn{background:#fffbeb;color:#b45309;border-color:#fde68a}
.alert{border-radius:8px;padding:10px 14px;font-size:13.5px;margin:0 0 10px;border:1px solid transparent}
.alert.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.btn{display:inline-block;background:#2563eb;border:1px solid #2563eb;color:#fff;padding:9px 18px;border-radius:8px;
 font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none}
.btn:hover{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.btn.ghost{background:#fff;color:#374151;border-color:var(--line)}
.btn.ghost:hover{background:#f8fafc;color:#2563eb}
.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px}
form{display:inline}
.hint{color:var(--muted);font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Qndocs 诊断与修复</h1>
  <p class="lead">后台打不开时用这里体检：检查数据表是否完整、一键补建缺失的表与字段、查看错误日志。</p>

<?php if (!$granted): ?>
  <div class="card"><h2>需要访问凭证</h2><div class="bd">
    <p>本页面包含数据库结构信息，因此需要凭证才能打开。两种方式：</p>
    <ul class="kv">
      <li><span>方式一</span><b>用管理员账号登录后台后，直接访问 /check.php</b></li>
      <li><span>方式二</span><b>打开 <code>data/config.php</code>，找到 <code>hash_key</code>，取前 12 位，访问 /check.php?key=这12位</b></li>
    </ul>
    <p class="hint">例如：<code>/check.php?key=a1b2c3d4e5f6</code></p>
    <div class="actions" style="margin-top:14px">
      <a class="btn ghost" href="<?= e(admin_url('login.php')) ?>">去登录后台</a>
      <a class="btn ghost" href="<?= e(qn_url('')) ?>">返回前台</a>
    </div>
  </div></div>
<?php else: ?>

  <?php foreach ($messages as $item): ?>
    <div class="alert <?= $item[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($item[1]) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <h2>数据库结构检查</h2>
    <div class="bd">
      <?php if (!$dbInfo['ok']): ?>
        <div class="alert err">数据库连接异常：<?= e($dbInfo['message']) ?></div>
      <?php elseif (!$broken): ?>
        <div class="alert ok">全部 <?= count($status) ?> 张数据表结构完整。</div>
      <?php else: ?>
        <div class="alert err">
          发现 <strong><?= count($broken) ?></strong> 张表存在问题：<?= e(implode('、', $broken)) ?>
          —— 这通常就是后台白屏的原因。点击下面的按钮补建后即可恢复。
        </div>
      <?php endif; ?>

      <?php if ($dbInfo['ok']): ?>
      <table>
        <thead><tr><th>数据表</th><th>状态</th><th>行数</th><th>缺失字段</th></tr></thead>
        <tbody>
        <?php foreach ($status as $table => $info): ?>
          <tr>
            <td><code><?= e($info['table']) ?></code></td>
            <td>
              <?php if (!$info['exists']): ?>
                <span class="tag err">表不存在</span>
              <?php elseif ($info['missing']): ?>
                <span class="tag warn">缺字段</span>
              <?php else: ?>
                <span class="tag ok">正常</span>
              <?php endif; ?>
            </td>
            <td><?= $info['exists'] ? (int) $info['count'] : '—' ?></td>
            <td><?= $info['missing'] ? e(implode('、', $info['missing'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="actions" style="margin-top:16px">
        <form method="post" action="<?= e(qn_url('check.php' . ($byKey ? '?key=' . rawurlencode($key) : ''))) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="repair">
          <input type="hidden" name="key" value="<?= e($key) ?>">
          <button class="btn" type="submit"><?= $broken ? '补建缺失的表与字段' : '重新检查并修复' ?></button>
        </form>
        <a class="btn ghost" href="<?= e(qn_url('check.php' . ($byKey ? '?key=' . rawurlencode($key) : ''))) ?>">重新检查</a>
        <a class="btn ghost" href="<?= e(admin_url('')) ?>">进入后台</a>
      </div>
      <p class="hint" style="margin-top:12px">补建操作只会新增缺失的表/字段，不会删除或覆盖已有数据。</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>运行环境</h2>
    <div class="bd">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px">
        <ul class="kv">
          <?php foreach ($env as $name => $value): ?>
            <li><span><?= e($name) ?></span><b><?= e($value) ?></b></li>
          <?php endforeach; ?>
        </ul>
        <ul class="kv">
          <?php foreach ($dirs as $name => $path): ?>
            <?php $ok = qn_writable($path); ?>
            <li><span><?= e($name) ?> 可写</span>
              <b><?= $ok ? '<span class="tag ok">是</span>' : '<span class="tag err">否</span>' ?></b></li>
          <?php endforeach; ?>
          <li><span>根 .htaccess</span><b><?= is_file(QN_ROOT . '/.htaccess') ? '已生成' : '<span class="tag warn">缺失</span>' ?></b></li>
          <li><span>install.php</span><b><?= is_file(QN_ROOT . '/install.php') ? '<span class="tag warn">建议删除</span>' : '已删除' ?></b></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>错误日志（最近 40 行）</h2>
    <div class="bd">
      <?php if (!$logLines): ?>
        <p class="hint">暂无日志。程序出错时错误会记录到 data/logs/app.log。</p>
      <?php else: ?>
        <pre><?= e(implode("\n", $logLines)) ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>后台仍然打不开时</h2>
    <div class="bd">
      <ul class="kv">
        <li><span>1</span><b>确认已把全部程序文件完整上传（新旧文件混用会报“未定义函数”类错误）</b></li>
        <li><span>2</span><b>在面板里重启一次 PHP（清 opcache），然后 Ctrl+F5 强刷浏览器</b></li>
        <li><span>3</span><b>在 data/ 目录新建空文件 debug.lock，可以看到 PHP 原始报错</b></li>
        <li><span>4</span><b>把上面的日志内容提供给开发者，可快速定位</b></li>
      </ul>
      <p class="hint">排障完成后建议删除 debug.lock 与 install.php。</p>
    </div>
  </div>

<?php endif; ?>
</div>
</body>
</html>
