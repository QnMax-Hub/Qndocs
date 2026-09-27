<?php
/**
 * Qndocs - 文档管理
 */

require_once __DIR__ . '/_common.php';

/* ------------------------------------------------------------------ 操作 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $action = (string) post('action');
    $id     = (int) post('id');
    $doc    = $id > 0 ? DB::one('SELECT * FROM {docs} WHERE id = ?', [$id]) : null;

    switch ($action) {
        case 'delete':
            if ($doc) {
                DB::update('docs', ['parent_id' => (int) $doc['parent_id']], 'parent_id = ?', [$id]);
                DB::delete('revisions', 'doc_id = ?', [$id]);
                DB::delete('doc_tags', 'doc_id = ?', [$id]);
                DB::delete('docs', 'id = ?', [$id]);
                if ((int) opt('home_doc_id', 0) === $id) {
                    set_opt('home_doc_id', '0');
                }
                $stat = qn_rebuild_static();
                flash('已删除「' . $doc['title'] . '」，子文档已上移到上一级。静态页面重建 ' . $stat['ok'] . ' 个。');
            }
            break;

        case 'duplicate':
            if ($doc) {
                $slug = qn_slugify($doc['slug'] . '-copy');
                $n    = 1;
                while (DB::val('SELECT id FROM {docs} WHERE slug = ?', [$slug])) {
                    $slug = qn_slugify($doc['slug'] . '-copy-' . (++$n));
                }
                $newId = DB::insert('docs', [
                    'parent_id'  => (int) $doc['parent_id'],
                    'title'      => $doc['title'] . '（副本）',
                    'slug'       => $slug,
                    'type'       => $doc['type'],
                    'content'    => $doc['content'],
                    'description' => $doc['description'],
                    'keywords'   => $doc['keywords'],
                    'sort'       => (int) $doc['sort'] + 1,
                    'status'     => 'draft',
                    'password'   => '',
                    'is_home'    => 0,
                    'in_nav'     => (int) $doc['in_nav'],
                    'views'      => 0,
                    'author_id'  => current_user_id(),
                    'created_at' => qn_now(),
                    'updated_at' => qn_now(),
                ]);
                flash('已复制为草稿「' . $doc['title'] . '（副本）」。');
                redirect(admin_url('editor.php?id=' . $newId));
            }
            break;

        case 'status':
            $status = (string) post('status');
            if ($doc && in_array($status, ['public', 'password', 'private', 'draft'], true)) {
                DB::update('docs', ['status' => $status, 'updated_at' => qn_now()], 'id = ?', [$id]);
                $stat = qn_rebuild_static();
                flash('「' . $doc['title'] . '」状态已更新。静态页面重建 ' . $stat['ok'] . ' 个。');
            }
            break;

        case 'nav':
            if ($doc) {
                DB::update('docs', ['in_nav' => (int) $doc['in_nav'] === 1 ? 0 : 1], 'id = ?', [$id]);
                qn_rebuild_static();
                flash('已切换「' . $doc['title'] . '」在导航中的显示。');
            }
            break;

        case 'home':
            if ($doc) {
                DB::exec('UPDATE {docs} SET is_home = 0');
                DB::update('docs', ['is_home' => 1, 'status' => 'public'], 'id = ?', [$id]);
                set_opt('home_doc_id', (string) $id);
                qn_rebuild_static();
                flash('已把「' . $doc['title'] . '」设为首页文档。');
            }
            break;

        case 'move':
            if ($doc) {
                $dir = (string) post('dir') === 'up' ? -1 : 1;
                $siblings = DB::all('SELECT id FROM {docs} WHERE parent_id = ? ORDER BY sort ASC, id ASC', [(int) $doc['parent_id']]);
                $ids      = array_map('intval', array_column($siblings, 'id'));
                $pos      = array_search($id, $ids, true);
                $target   = $pos === false ? false : $pos + $dir;
                if ($pos !== false && $target >= 0 && $target < count($ids)) {
                    $order = $ids;
                    $tmp   = $order[$pos];
                    $order[$pos]    = $order[$target];
                    $order[$target] = $tmp;
                    foreach ($order as $index => $sid) {
                        DB::update('docs', ['sort' => $index * 10], 'id = ?', [(int) $sid]);
                    }
                    qn_rebuild_static();
                    flash('排序已更新。');
                }
            }
            break;

        case 'rename':
            if ($doc) {
                $title = trim((string) post('title'));
                if ($title !== '') {
                    DB::update('docs', ['title' => mb_substr($title, 0, 200, 'UTF-8'), 'updated_at' => qn_now()], 'id = ?', [$id]);
                    qn_rebuild_static();
                    flash('标题已更新。');
                }
            }
            break;
    }

    redirect(admin_url('docs.php'));
}

/* ------------------------------------------------------------------ 列表 */

$filter = (string) get('status', '');
$rows   = ad_doc_rows();
if ($filter !== '' && in_array($filter, ['public', 'password', 'private', 'draft'], true)) {
    $rows = array_values(array_filter($rows, function ($row) use ($filter) {
        return $row['status'] === $filter;
    }));
}

$counts = [
    'all'      => (int) DB::val('SELECT COUNT(*) FROM {docs}'),
    'public'   => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'public'"),
    'password' => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'password'"),
    'private'  => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'private'"),
    'draft'    => (int) DB::val("SELECT COUNT(*) FROM {docs} WHERE status = 'draft'"),
];
$homeId = (int) opt('home_doc_id', 0);

admin_head('文档管理', 'docs.php',
    '<a class="ad-btn" href="' . e(admin_url('editor.php')) . '">' . qn_icon('plus') . '新建文档</a>');
?>

<div class="ad-tabs">
  <a class="<?= $filter === '' ? 'on' : '' ?>" href="<?= e(admin_url('docs.php')) ?>">全部 <em><?= $counts['all'] ?></em></a>
  <a class="<?= $filter === 'public' ? 'on' : '' ?>" href="<?= e(admin_url('docs.php?status=public')) ?>">公开 <em><?= $counts['public'] ?></em></a>
  <a class="<?= $filter === 'password' ? 'on' : '' ?>" href="<?= e(admin_url('docs.php?status=password')) ?>">密码 <em><?= $counts['password'] ?></em></a>
  <a class="<?= $filter === 'private' ? 'on' : '' ?>" href="<?= e(admin_url('docs.php?status=private')) ?>">私有 <em><?= $counts['private'] ?></em></a>
  <a class="<?= $filter === 'draft' ? 'on' : '' ?>" href="<?= e(admin_url('docs.php?status=draft')) ?>">草稿 <em><?= $counts['draft'] ?></em></a>
</div>

<?php ad_card_open(); ?>
  <div class="ad-toolbar">
    <input id="adDocSearch" class="ad-input sm" type="search" placeholder="按标题或 slug 过滤…" style="max-width:280px">
    <span class="ad-muted">共 <?= count($rows) ?> 篇 · 拖动顺序可用上下箭头调整</span>
  </div>

  <div class="ad-table-wrap">
    <table class="ad-table ad-table-docs" id="adDocTable">
      <thead>
        <tr>
          <th style="min-width:280px">标题 / 层级</th>
          <th>Slug</th>
          <th>类型</th>
          <th>状态</th>
          <th class="num">浏览</th>
          <th>更新</th>
          <th class="ops">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="ad-empty">还没有文档，<a href="<?= e(admin_url('editor.php')) ?>">新建第一篇</a>。</td></tr>
      <?php endif; ?>

      <?php foreach ($rows as $row): ?>
        <tr data-search="<?= e(mb_strtolower($row['title'] . ' ' . $row['slug'], 'UTF-8')) ?>">
          <td class="ad-cell-main">
            <span class="ad-tree" style="padding-left:<?= (int) $row['depth'] * 20 ?>px">
              <?php if ((int) $row['depth'] > 0): ?><i class="ad-tree-line"></i><?php endif; ?>
              <a href="<?= e(admin_url('editor.php?id=' . (int) $row['id'])) ?>"><?= e($row['title']) ?></a>
              <?php if ((int) $row['is_home'] === 1 || $homeId === (int) $row['id']): ?><span class="ad-badge home">首页</span><?php endif; ?>
              <?php if ((int) $row['in_nav'] !== 1): ?><span class="ad-badge muted">隐藏导航</span><?php endif; ?>
            </span>
          </td>
          <td>
            <code class="ad-code"><?= e(doc_url($row)) ?></code>
            <button type="button" class="ad-mini mt8"
                    data-copy="<?= e(qn_base_url() . doc_url($row) . (strpos(doc_url($row), '?') === -1 ? '?' : '&') . 'fragment=1') ?>">复制嵌入地址</button>
          </td>
          <td><span class="ad-badge info"><?= $row['type'] === 'text' ? '纯文本' : ($row['type'] === 'markdown' ? 'MD' : '富文本') ?></span></td>
          <td><?= ad_status_label($row['status']) ?></td>
          <td class="num"><?= (int) $row['views'] ?></td>
          <td class="ad-muted"><?= e(qn_time_ago((string) $row['updated_at'])) ?></td>
          <td class="ops">
            <div class="ad-ops">
              <a class="ad-icon-btn" href="<?= e(admin_url('editor.php?id=' . (int) $row['id'])) ?>" title="编辑"><?= qn_icon('edit') ?></a>
              <a class="ad-icon-btn" href="<?= $row['status'] === 'draft' ? e(admin_url('editor.php?id=' . (int) $row['id'])) : e(doc_url($row)) ?>"
                 target="_blank" rel="noopener" title="预览"><?= qn_icon('eye') ?></a>

              <form method="post" class="ad-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="ad-icon-btn" type="submit" title="上移"><?= qn_icon('up') ?></button>
              </form>
              <form method="post" class="ad-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="ad-icon-btn" type="submit" title="下移"><?= qn_icon('down') ?></button>
              </form>

              <div class="ad-menu-wrap">
                <button class="ad-icon-btn" type="button" data-menu="1" title="更多">⋯</button>
                <div class="ad-menu">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="status" value="<?= $row['status'] === 'public' ? 'draft' : 'public' ?>">
                    <button type="submit"><?= $row['status'] === 'public' ? '转为草稿（下架）' : '发布为公开' ?></button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="status" value="password">
                    <button type="submit">设为需要密码</button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="status" value="private">
                    <button type="submit">设为私有（仅成员）</button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="home">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit">设为首页文档</button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="nav">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit"><?= (int) $row['in_nav'] === 1 ? '从导航中隐藏' : '显示在导航中' ?></button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="duplicate">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit">复制为草稿副本</button>
                  </form>
                  <form method="post" data-confirm="确定删除「<?= e($row['title']) ?>」吗？该操作不可撤销（子文档会提升到上一级）。">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="danger">删除文档</button>
                  </form>
                </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php ad_card_close(); ?>

<?php admin_foot(); ?>
