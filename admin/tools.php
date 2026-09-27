<?php
/**
 * Qndocs - 工具（静态页面、备份、系统信息）
 */

require_once __DIR__ . '/_common.php';

/** 生成数据库备份 SQL */
function ad_backup_sql(): string
{
    $pdo   = DB::pdo();
    $out   = "-- Qndocs 数据库备份\n-- 站点：" . (string) opt('site_name', 'Qndocs') . "\n-- 生成时间：" . qn_now() . "\n\n";
    $out  .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = DB::all('SHOW TABLES');
    foreach ($tables as $row) {
        $values = array_values($row);
        $table  = (string) $values[0];
        $create = DB::one('SHOW CREATE TABLE `' . $table . '`');
        $ddl    = $create ? array_values($create) : [];
        $out   .= "DROP TABLE IF EXISTS `{$table}`;\n" . ($ddl[1] ?? '') . ";\n\n";

        $rows = DB::all('SELECT * FROM `' . $table . '`');
        if ($rows) {
            $cols = array_keys($rows[0]);
            $out .= 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n";
            $chunks = [];
            foreach ($rows as $item) {
                $pieces = [];
                foreach ($cols as $col) {
                    $value    = $item[$col];
                    $pieces[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }
                $chunks[] = '(' . implode(', ', $pieces) . ')';
            }
            $out .= implode(",\n", $chunks) . ";\n\n";
        }
    }
    return $out . "SET FOREIGN_KEY_CHECKS=1;\n-- 备份结束\n";
}

/** 导出站点数据（JSON） */
function ad_export_json(): string
{
    $data = [
        'generator'  => 'Qndocs ' . QN_VERSION,
        'exported_at' => qn_now(),
        'site'       => [
            'name'   => (string) opt('site_name', 'Qndocs'),
            'domain' => (string) opt('site_domain', ''),
        ],
        'settings'   => DB::all('SELECT k, v FROM {settings}'),
        'docs'       => DB::all('SELECT * FROM {docs} ORDER BY id ASC'),
        'tags'       => DB::all('SELECT * FROM {tags}'),
        'doc_tags'   => DB::all('SELECT * FROM {doc_tags}'),
    ];
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $action = (string) post('action');

    if ($action === 'backup') {
        $sql = ad_backup_sql();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="qndocs-backup-' . date('Ymd-His') . '.sql"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }

    if ($action === 'export') {
        $json = ad_export_json();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="qndocs-data-' . date('Ymd-His') . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    if ($action === 'rebuild') {
        $stat = qn_rebuild_static();
        flash('静态页面重建完成：成功 ' . $stat['ok'] . ' 个，跳过 ' . $stat['skip'] . ' 个，失败 ' . $stat['fail'] . ' 个。');
        redirect(admin_url('tools.php'));
    }

    if ($action === 'clearcache') {
        qn_purge_static();
        flash('静态缓存已清空，前台会在访问时按需重新生成。', 'info');
        redirect(admin_url('tools.php'));
    }

    if ($action === 'clearlog') {
        @file_put_contents(QN_DATA . '/logs/app.log', '');
        flash('运行日志已清空。', 'info');
        redirect(admin_url('tools.php'));
    }
}

$pageDir   = QN_DATA . '/pages';
$pageCount = count(glob($pageDir . '/*.html') ?: []);
$pageSize  = 0;
foreach (glob($pageDir . '/*.html') ?: [] as $file) {
    $pageSize += (int) filesize($file);
}
$uploadSize = 0;
$uploadCount = 0;
foreach (glob(QN_UPLOAD_DIR . '/*/*') ?: [] as $file) {
    if (is_file($file)) {
        $uploadSize += (int) filesize($file);
        $uploadCount++;
    }
}
$info  = qn_server_info();
$table = DB::all('SHOW TABLE STATUS');

$logFile  = QN_DATA . '/logs/app.log';
$logLines = [];
if (is_file($logFile)) {
    $raw = trim((string) file_get_contents($logFile));
    if ($raw !== '') {
        $logLines = array_slice(preg_split('/\r\n|\r|\n/', $raw), -40);
    }
}

admin_head('工具', 'tools.php');
?>

<div class="ad-grid-2">
  <?php ad_card_open('静态页面'); ?>
    <ul class="ad-kv">
      <li><span>缓存文件</span><b><?= $pageCount ?> 个</b></li>
      <li><span>占用空间</span><b><?= e(qn_bytes($pageSize)) ?></b></li>
      <li><span>目录</span><b class="ad-wrap-any">data/pages</b></li>
      <li><span>静态输出</span><b><?= (int) opt('static_cache', 1) === 1 ? '已开启' : '已关闭' ?></b></li>
    </ul>
    <div class="ad-actions-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rebuild">
        <button class="ad-btn" type="submit"><?= qn_icon('refresh') ?>重新生成全部页面</button></form>
      <form method="post" data-confirm="清空后，前台页面会在被访问时按需重新生成，确定继续？">
        <?= csrf_field() ?><input type="hidden" name="action" value="clearcache">
        <button class="ad-btn ghost" type="submit">清空缓存</button></form>
    </div>
  <?php ad_card_close(); ?>

  <?php ad_card_open('备份与导出'); ?>
    <p class="ad-muted">建议在批量修改样式或升级前导出一份备份。</p>
    <div class="ad-actions-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
        <button class="ad-btn" type="submit"><?= qn_icon('upload') ?>下载数据库 SQL 备份</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="export">
        <button class="ad-btn ghost" type="submit">导出站点数据 JSON</button></form>
    </div>
    <ul class="ad-kv mt16">
      <li><span>数据库表</span><b><?= count($table) ?> 张</b></li>
      <li><span>uploads 文件</span><b><?= $uploadCount ?> 个 / <?= e(qn_bytes($uploadSize)) ?></b></li>
    </ul>
    <p class="ad-hint">数据库结构检查与修复：<a href="<?= e(qn_url('check.php')) ?>">打开「诊断与修复」</a>（后台异常打不开时也能用它排查）</p>
  <?php ad_card_close(); ?>
</div>

<?php ad_card_open('系统信息'); ?>
  <div class="ad-grid-3">
    <ul class="ad-kv">
      <li><span>Qndocs</span><b>v<?= e(QN_VERSION) ?></b></li>
      <li><span>PHP</span><b><?= e($info['php']) ?></b></li>
      <li><span>MySQL</span><b><?= e($info['mysql']) ?></b></li>
      <li><span>运行方式</span><b><?= e($info['sapi']) ?></b></li>
    </ul>
    <ul class="ad-kv">
      <li><span>服务器</span><b class="ad-wrap-any"><?= e($info['server']) ?></b></li>
      <li><span>操作系统</span><b><?= e($info['os']) ?></b></li>
      <li><span>上传限制</span><b><?= e(ini_get('upload_max_filesize') ?: '未知') ?></b></li>
      <li><span>最大执行时间</span><b><?= e(ini_get('max_execution_time') ?: '未知') ?> 秒</b></li>
    </ul>
    <ul class="ad-kv">
      <li><span>data 可写</span><b><?= qn_writable(QN_DATA) ? '是' : '<span class="ad-badge warn">否</span>' ?></b></li>
      <li><span>data/pages 可写</span><b><?= qn_writable($pageDir) ? '是' : '<span class="ad-badge warn">否</span>' ?></b></li>
      <li><span>uploads 可写</span><b><?= qn_writable(QN_UPLOAD_DIR) ? '是' : '<span class="ad-badge warn">否</span>' ?></b></li>
      <li><span>.htaccess</span><b><?= is_file(QN_ROOT . '/.htaccess') ? '已生成' : '<span class="ad-badge warn">缺失</span>' ?></b></li>
    </ul>
  </div>
<?php ad_card_close(); ?>

<?php ad_card_open('数据库表'); ?>
  <div class="ad-table-wrap">
    <table class="ad-table">
      <thead><tr><th>表名</th><th class="num">行数</th><th class="num">数据</th><th class="num">索引</th><th>引擎</th><th>校对集</th></tr></thead>
      <tbody>
      <?php foreach ($table as $t): ?>
        <tr>
          <td><code class="ad-code"><?= e((string) $t['Name']) ?></code></td>
          <td class="num"><?= (int) ($t['Rows'] ?? 0) ?></td>
          <td class="num"><?= e(qn_bytes((int) ($t['Data_length'] ?? 0))) ?></td>
          <td class="num"><?= e(qn_bytes((int) ($t['Index_length'] ?? 0))) ?></td>
          <td><?= e((string) ($t['Engine'] ?? '—')) ?></td>
          <td class="ad-muted"><?= e((string) ($t['Collation'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php ad_card_close(); ?>

<?php ad_card_open('运行日志（最近 40 行）'); ?>
  <?php if (!$logLines): ?>
    <p class="ad-muted">暂无日志记录。程序出问题时，错误也会写到这里。</p>
  <?php else: ?>
    <pre class="ad-log"><?= e(implode("\n", $logLines)) ?></pre>
  <?php endif; ?>
  <div class="ad-actions-row">
    <form method="post" data-confirm="确定清空运行日志吗？">
      <?= csrf_field() ?><input type="hidden" name="action" value="clearlog">
      <button class="ad-btn ghost" type="submit">清空日志</button>
    </form>
    <span class="ad-muted">日志文件：data/logs/app.log</span>
  </div>
<?php ad_card_close(); ?>

<?php admin_foot(); ?>
