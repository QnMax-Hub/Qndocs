<?php
/**
 * Qndocs - 修订历史
 */

require_once __DIR__ . '/_common.php';

/** 简易行级差异对比 */
function ad_diff_html(string $old, string $new): string
{
    $a = preg_split('/\r\n|\r|\n/', $old);
    $b = preg_split('/\r\n|\r|\n/', $new);
    $na = count($a);
    $nb = count($b);

    $pre = 0;
    while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) {
        $pre++;
    }
    $suf = 0;
    while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) {
        $suf++;
    }

    $html = '<div class="ad-diff">';
    for ($i = 0; $i < $pre; $i++) {
        $html .= '<div class="ln same">' . e($a[$i]) . '</div>';
    }
    for ($i = $pre; $i < $na - $suf; $i++) {
        $html .= '<div class="ln del">− ' . e($a[$i]) . '</div>';
    }
    for ($i = $pre; $i < $nb - $suf; $i++) {
        $html .= '<div class="ln add">+ ' . e($b[$i]) . '</div>';
    }
    for ($i = $na - $suf; $i < $na; $i++) {
        $html .= '<div class="ln same">' . e($a[$i]) . '</div>';
    }
    return $html . '</div>';
}

$docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$revId = isset($_GET['rev']) ? (int) $_GET['rev'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    if ((string) post('action') === 'rollback') {
        $rid = (int) post('rev');
        $did = (int) post('doc_id');
        $rev = DB::one('SELECT * FROM {revisions} WHERE id = ? AND doc_id = ?', [$rid, $did]);
        $doc = DB::one('SELECT * FROM {docs} WHERE id = ?', [$did]);
        if ($rev && $doc) {
            DB::insert('revisions', [
                'doc_id'     => $did,
                'title'      => $doc['title'],
                'type'       => $doc['type'],
                'content'    => $doc['content'],
                'user_id'    => current_user_id(),
                'username'   => current_user_name(),
                'created_at' => qn_now(),
            ]);
            DB::update('docs', ['content' => $rev['content'], 'type' => $rev['type'], 'updated_at' => qn_now()], 'id = ?', [$did]);
            qn_rebuild_static();
            flash('已回滚到 ' . qn_date((string) $rev['created_at']) . ' 的版本。');
        } else {
            flash('版本不存在。', 'error');
        }
        redirect(admin_url('editor.php?id=' . $did));
    }
    redirect(admin_url('revisions.php'));
}

$doc      = $docId > 0 ? DB::one('SELECT * FROM {docs} WHERE id = ?', [$docId]) : null;
$revisions = $doc ? DB::all('SELECT * FROM {revisions} WHERE doc_id = ? ORDER BY id DESC LIMIT 50', [$docId]) : [];
$current   = null;
foreach ($revisions as $row) {
    if ((int) $row['id'] === $revId) {
        $current = $row;
        break;
    }
}
$listDocs = DB::all('SELECT d.id, d.title, d.updated_at, COUNT(r.id) AS revs
                     FROM {docs} d JOIN {revisions} r ON r.doc_id = d.id
                     GROUP BY d.id, d.title, d.updated_at ORDER BY MAX(r.id) DESC LIMIT 50');

admin_head('修订历史', 'revisions.php',
    $doc ? '<a class="ad-btn ghost sm" href="' . e(admin_url('editor.php?id=' . (int) $doc['id'])) . '">返回编辑器</a>' : '');
?>

<?php if (!$doc): ?>
  <?php ad_card_open('有历史记录的文档'); ?>
    <?php if (!$listDocs): ?>
      <p class="ad-muted">还没有任何修订记录。文档每次保存都会自动留档。</p>
    <?php else: ?>
      <table class="ad-table">
        <thead><tr><th>文档</th><th class="num">历史版本</th><th>最后更新</th><th class="ops"></th></tr></thead>
        <tbody>
        <?php foreach ($listDocs as $row): ?>
          <tr>
            <td class="ad-cell-main"><a href="<?= e(admin_url('revisions.php?id=' . (int) $row['id'])) ?>"><?= e($row['title']) ?></a></td>
            <td class="num"><?= (int) $row['revs'] ?></td>
            <td class="ad-muted"><?= e(qn_time_ago((string) $row['updated_at'])) ?></td>
            <td class="ops"><a class="ad-mini" href="<?= e(admin_url('editor.php?id=' . (int) $row['id'])) ?>">编辑</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php ad_card_close(); ?>

<?php else: ?>
  <?php ad_card_open('「' . $doc['title'] . '」的历史版本（最多保留 30 个）'); ?>
    <?php if (!$revisions): ?>
      <p class="ad-muted">该文档还没有历史版本，再保存一次即可生成。</p>
    <?php else: ?>
      <table class="ad-table">
        <thead><tr><th>版本</th><th>标题</th><th>类型</th><th>编辑人</th><th>时间</th><th class="ops">操作</th></tr></thead>
        <tbody>
        <?php foreach ($revisions as $row): ?>
          <tr class="<?= $current && (int) $current['id'] === (int) $row['id'] ? 'on' : '' ?>">
            <td>#<?= (int) $row['id'] ?></td>
            <td class="ad-cell-main"><?= e($row['title']) ?></td>
            <td><span class="ad-badge info"><?= $row['type'] === 'text' ? '纯文本' : ($row['type'] === 'markdown' ? 'MD' : '富文本') ?></span></td>
            <td><?= e($row['username'] !== '' ? $row['username'] : '—') ?></td>
            <td class="ad-muted"><?= e(qn_date((string) $row['created_at'])) ?></td>
            <td class="ops">
              <div class="ad-ops">
                <a class="ad-mini" href="<?= e(admin_url('revisions.php?id=' . (int) $doc['id'] . '&rev=' . (int) $row['id'])) ?>">对比</a>
                <form method="post" class="ad-inline" data-confirm="确定回滚到该版本吗？当前内容会先被另存为一个历史版本。">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rollback">
                  <input type="hidden" name="doc_id" value="<?= (int) $doc['id'] ?>">
                  <input type="hidden" name="rev" value="<?= (int) $row['id'] ?>">
                  <button class="ad-mini" type="submit">回滚</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php ad_card_close(); ?>

  <?php if ($current): ?>
    <?php ad_card_open('对比：版本 #' . (int) $current['id'] . '（绿色为历史内容，红色为当前内容）'); ?>
      <?= ad_diff_html((string) $current['content'], (string) $doc['content']) ?>
    <?php ad_card_close(); ?>
  <?php endif; ?>
<?php endif; ?>

<?php admin_foot(); ?>
