<?php
/**
 * Qndocs - 文档编辑器
 */

require_once __DIR__ . '/_common.php';

/** 某个文档的全部后代 ID */
function ad_descendant_ids(int $id): array
{
    $out   = [];
    $stack = [$id];
    while ($stack) {
        $current = (int) array_pop($stack);
        $children = DB::all('SELECT id FROM {docs} WHERE parent_id = ?', [$current]);
        foreach ($children as $child) {
            $cid = (int) $child['id'];
            if (!in_array($cid, $out, true)) {
                $out[]   = $cid;
                $stack[] = $cid;
            }
        }
    }
    return $out;
}

$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isNew = $id <= 0;
$doc   = $isNew ? null : DB::one('SELECT * FROM {docs} WHERE id = ?', [$id]);

if (!$isNew && !$doc) {
    flash('文档不存在或已被删除。', 'error');
    redirect(admin_url('docs.php'));
}

/* ------------------------------------------------------------------ 保存 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();

    $title = trim((string) post('title'));
    if ($title === '') {
        flash('文档标题不能为空。', 'error');
        redirect(admin_url('editor.php' . ($isNew ? '' : '?id=' . $id)));
    }

    $type  = post('type') === 'text' ? 'text' : 'html';
    $slug  = qn_slugify((string) post('slug'), qn_slugify($title, 'doc'));
    $taken = DB::val('SELECT id FROM {docs} WHERE slug = ? AND id <> ?', [$slug, $id]);
    if ($taken) {
        $base = $slug;
        $n    = 1;
        do {
            $slug  = $base . '-' . (++$n);
            $taken = DB::val('SELECT id FROM {docs} WHERE slug = ? AND id <> ?', [$slug, $id]);
        } while ($taken && $n < 50);
    }

    $status = (string) post('status');
    if (!in_array($status, ['public', 'password', 'private', 'draft'], true)) {
        $status = 'draft';
    }

    $parentId = (int) post('parent_id');
    if ($parentId === $id) {
        $parentId = 0;
    }
    if ($parentId > 0 && !$isNew) {
        $exclude = array_merge([$id], ad_descendant_ids($id));
        if (in_array($parentId, $exclude, true)) {
            $parentId = (int) $doc['parent_id'];
        }
    }
    if ($parentId > 0 && !DB::val('SELECT id FROM {docs} WHERE id = ?', [$parentId])) {
        $parentId = 0;
    }

    $data = [
        'parent_id'   => $parentId,
        'title'       => mb_substr($title, 0, 200, 'UTF-8'),
        'slug'        => $slug,
        'type'        => $type,
        'content'     => (string) post('content'),
        'description' => mb_substr(trim((string) post('description')), 0, 500, 'UTF-8'),
        'keywords'    => mb_substr(trim((string) post('keywords')), 0, 255, 'UTF-8'),
        'sort'        => (int) post('sort'),
        'status'      => $status,
        'in_nav'      => post('in_nav') ? 1 : 0,
        'updated_at'  => qn_now(),
    ];

    // 密码
    $password = (string) post('password');
    if ($status === 'password') {
        if ($password !== '') {
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        } elseif (!$isNew && isset($doc['password'])) {
            $data['password'] = (string) $doc['password'];
        }
    } else {
        $data['password'] = '';
    }

    // 首页
    if (post('is_home')) {
        DB::exec('UPDATE {docs} SET is_home = 0');
        $data['is_home'] = 1;
        if ($status === 'draft' || $status === 'private') {
            $data['status'] = 'public';
            $status         = 'public';
        }
    } else {
        $data['is_home'] = 0;
        if ((int) opt('home_doc_id', 0) === $id) {
            set_opt('home_doc_id', '0');
        }
    }

    if ($isNew) {
        $data['created_at'] = qn_now();
        $data['author_id']  = current_user_id();
        $id                 = DB::insert('docs', $data);
        $doc                = DB::one('SELECT * FROM {docs} WHERE id = ?', [$id]);
        flash('文档「' . $data['title'] . '」创建成功。');
    } else {
        DB::insert('revisions', [
            'doc_id'     => $id,
            'title'      => $doc['title'],
            'type'       => $doc['type'],
            'content'    => $doc['content'],
            'user_id'    => current_user_id(),
            'username'   => current_user_name(),
            'created_at' => qn_now(),
        ]);
        $cut = DB::one('SELECT id FROM {revisions} WHERE doc_id = ? ORDER BY id DESC LIMIT 1 OFFSET 29', [$id]);
        if ($cut) {
            DB::delete('revisions', 'doc_id = ? AND id < ?', [$id, (int) $cut['id']]);
        }
        DB::update('docs', $data, 'id = ?', [$id]);
        $doc = DB::one('SELECT * FROM {docs} WHERE id = ?', [$id]);
        flash('已保存「' . $data['title'] . '」。');
    }

    // 标签
    $tags = array_filter(array_map('trim', explode(',', (string) post('tags'))));
    qn_sync_tags($id, $tags);

    // 静态页面
    $stat = qn_rebuild_static();

    redirect(admin_url('editor.php?id=' . $id . '&saved=1'));
}

/* ------------------------------------------------------------------ 视图数据 */

$isNew    = $doc === null;
$parents  = ad_doc_rows();
$exclude  = [];
if (!$isNew) {
    $exclude = array_merge([$id], ad_descendant_ids($id));
}
$nameOf   = function (array $row) {
    return str_repeat('　', (int) ($row['depth'] ?? 0)) . ($row['depth'] > 0 ? '└ ' : '') . $row['title'];
};
$revisions = $isNew ? [] : DB::all('SELECT id, title, username, created_at FROM {revisions} WHERE doc_id = ? ORDER BY id DESC LIMIT 8', [$id]);
$tags      = $isNew ? [] : qn_doc_tags($doc);
$homeId    = (int) opt('home_doc_id', 0);

$content = $doc ? (string) $doc['content'] : '';
$type    = $doc ? $doc['type'] : 'html';

// 富文本编辑器：旧的 Markdown 文档先转成 HTML 显示，保存后即成为 HTML 内容
$editorHtml = $type === 'markdown' ? qn_md($content) : $content;
$editorType = ($type === 'text') ? 'text' : 'html';

admin_head($isNew ? '新建文档' : '编辑文档', 'docs.php',
    '<a class="ad-btn ghost sm" href="' . e(admin_url('docs.php')) . '">返回列表</a>');
?>

<form method="post" class="ad-editor" id="adEditorForm" data-id="<?= (int) ($doc['id'] ?? 0) ?>"
      action="<?= e(admin_url('editor.php' . ($isNew ? '' : '?id=' . $id))) ?>">
  <?= csrf_field() ?>

  <div class="ad-editor-grid">
    <div class="ad-editor-main">
      <input class="ad-input ad-title-input" type="text" name="title" required
             value="<?= e($doc ? $doc['title'] : '') ?>" placeholder="请输入文档标题…">

      <div class="ad-editor-bar">
        <div class="ad-seg" id="adTypeSeg">
          <button type="button" class="<?= $editorType === 'html' ? 'on' : '' ?>" data-ctype="html">富文本</button>
          <button type="button" class="<?= $editorType === 'text' ? 'on' : '' ?>" data-ctype="text">纯文本</button>
        </div>
        <div class="ad-seg" id="adViewSeg">
          <button type="button" class="on" data-view="edit">编辑</button>
          <button type="button" data-view="split">分栏</button>
          <button type="button" data-view="preview">预览</button>
        </div>
        <div class="ad-editor-hint">富文本＝所见即所得排版；纯文本＝原样保存（适合配置、命令、日志）</div>
      </div>

      <div class="ad-editor-panes" id="adPanes" data-view="edit">
        <div class="rt-wrap" id="rtWrap">
          <div class="rt-toolbar" id="rtToolbar">
            <select class="rt-select" id="rtBlock" title="段落格式">
              <option value="p">正文</option>
              <option value="h1">标题 1</option>
              <option value="h2">标题 2</option>
              <option value="h3">标题 3</option>
              <option value="h4">标题 4</option>
            </select>
            <span class="rt-sep"></span>
            <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
            <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
            <button type="button" data-cmd="underline" title="下划线"><u>U</u></button>
            <button type="button" data-cmd="strikeThrough" title="删除线"><s>S</s></button>
            <span class="rt-sep"></span>
            <button type="button" data-cmd="insertUnorderedList" title="无序列表">•&nbsp;列表</button>
            <button type="button" data-cmd="insertOrderedList" title="有序列表">1.&nbsp;列表</button>
            <button type="button" data-block="blockquote" title="引用">❝</button>
            <button type="button" data-block="pre" title="代码块">&lt;/&gt;</button>
            <span class="rt-sep"></span>
            <button type="button" data-cmd="justifyLeft" title="左对齐">⇤</button>
            <button type="button" data-cmd="justifyCenter" title="居中">↔</button>
            <button type="button" data-cmd="justifyRight" title="右对齐">⇥</button>
            <span class="rt-sep"></span>
            <button type="button" class="rt-color" data-color="#1f2937" style="color:#1f2937" title="默认色">A</button>
            <button type="button" class="rt-color" data-color="#2563eb" style="color:#2563eb" title="蓝色">A</button>
            <button type="button" class="rt-color" data-color="#dc2626" style="color:#dc2626" title="红色">A</button>
            <button type="button" class="rt-color" data-color="#16a34a" style="color:#16a34a" title="绿色">A</button>
            <span class="rt-sep"></span>
            <button type="button" data-action="link" title="插入链接">🔗</button>
            <button type="button" data-action="image" title="插入图片">🖼</button>
            <button type="button" data-action="table" title="插入 3×3 表格">▦</button>
            <button type="button" data-table="rowAdd" title="在光标所在行下方插入一行">＋行</button>
            <button type="button" data-table="rowDel" title="删除光标所在行">−行</button>
            <button type="button" data-table="colAdd" title="在光标所在列右侧插入一列">＋列</button>
            <button type="button" data-table="colDel" title="删除光标所在列">−列</button>
            <button type="button" data-action="hr" title="分割线">―</button>
            <button type="button" data-cmd="removeFormat" title="清除格式">Tx</button>
            <span class="rt-sep"></span>
            <button type="button" data-block-html="alert" title="提示框">提示</button>
            <button type="button" data-block-html="cards" title="卡片组">卡片</button>
            <button type="button" data-block-html="hero" title="标题区块">区块</button>
            <button type="button" data-block-html="btn" title="按钮">按钮</button>
            <span class="rt-sep"></span>
            <button type="button" data-cmd="undo" title="撤销">↶</button>
            <button type="button" data-cmd="redo" title="重做">↷</button>
            <button type="button" data-action="source" title="查看 / 编辑 HTML 源码">源码</button>
            <button type="button" data-action="fullscreen" title="全屏编辑">⛶</button>
          </div>

          <div class="rt-editor qn-doc" id="rtEditor" contenteditable="true" spellcheck="false"
               data-placeholder="在这里输入文档内容…"><?= $editorHtml ?></div>
          <textarea class="rt-source" id="rtSource" spellcheck="false" hidden></textarea>
          <textarea name="content" id="adContent" hidden><?= e($content) ?></textarea>
        </div>

        <div class="rt-wrap rt-plain-wrap" id="rtPlainWrap" hidden>
          <div class="rt-toolbar rt-toolbar-plain">
            <span class="rt-plain-tip">纯文本模式：内容原样保存，换行与空格都会保留；前台可用 <code>?text=1</code> 直接取纯文本。</span>
          </div>
          <textarea class="rt-plain" id="rtPlain" spellcheck="false"
                    placeholder="在此输入纯文本内容…"><?= e($type === 'text' ? $content : '') ?></textarea>
        </div>

        <div class="ad-preview-pane">
          <div class="ad-preview-bar">
            <span>预览（与前台输出完全一致）</span>
            <span>
              <select id="adPreviewWidth" class="ad-input xs">
                <option value="100%">自适应</option>
                <option value="1180px">桌面 1180</option>
                <option value="820px">平板 820</option>
                <option value="420px">手机 420</option>
              </select>
              <button type="button" class="ad-btn ghost sm" id="adRefreshPreview">刷新预览</button>
            </span>
          </div>
          <iframe id="adPreviewFrame" name="adPreview" class="ad-frame" title="预览"></iframe>
        </div>
      </div>
    </div>

    <aside class="ad-editor-side">
      <div class="ad-card">
        <div class="ad-card-bd">
          <button class="ad-btn block" type="submit"><?= qn_icon('save') ?><?= $isNew ? '创建文档' : '保存并发布' ?></button>
          <?php if (!$isNew && $doc['status'] !== 'draft'): ?>
            <a class="ad-btn ghost block mt8" href="<?= e(doc_url($doc)) ?>" target="_blank" rel="noopener"><?= qn_icon('eye') ?>查看前台页面</a>
            <button type="button" class="ad-btn ghost block mt8"
                    data-copy="<?= e(qn_base_url() . doc_url($doc) . (strpos(doc_url($doc), '?') === -1 ? '?' : '&') . 'fragment=1') ?>">复制嵌入地址（给软件用）</button>
          <?php endif; ?>
          <p class="ad-hint">快捷键：Ctrl / ⌘ + S 保存，Ctrl + Enter 刷新预览</p>
        </div>
      </div>

      <div class="ad-card">
        <header class="ad-card-hd"><h2>发布设置</h2></header>
        <div class="ad-card-bd ad-form">
          <label><span>状态</span>
            <select name="status" id="adStatus">
              <?php foreach (['public' => '公开（所有人可见）', 'password' => '密码访问', 'private' => '私有（仅成员）', 'draft' => '草稿（前台隐藏）'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $doc && $doc['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label id="adPasswordWrap"><span>访问密码<?= $doc && $doc['password'] !== '' ? '（留空表示不修改）' : '' ?></span>
            <input class="ad-input" type="text" name="password" value="" placeholder="<?= $doc && $doc['password'] !== '' ? '已设置，留空则保持不变' : '请输入访问密码' ?>">
          </label>

          <label><span>访问地址 Slug</span>
            <div class="ad-input-group">
              <input class="ad-input" type="text" name="slug" id="adSlug" value="<?= e($doc ? $doc['slug'] : '') ?>" placeholder="留空自动生成">
              <button class="ad-btn ghost sm" type="button" id="adSlugAuto">自动</button>
            </div>
            <em class="ad-slug-preview" id="adSlugPreview">/<?= e($doc ? $doc['slug'] : '') ?>.html</em>
          </label>

          <label><span>上级目录</span>
            <select name="parent_id">
              <option value="0">（顶级）</option>
              <?php foreach ($parents as $row): ?>
                <?php if (in_array((int) $row['id'], $exclude, true)) { continue; } ?>
                <option value="<?= (int) $row['id'] ?>" <?= $doc && (int) $doc['parent_id'] === (int) $row['id'] ? 'selected' : '' ?>>
                  <?= e($nameOf($row)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <input type="hidden" name="type" value="html">
          <p class="ad-hint">内容以<strong>纯 HTML</strong> 保存：前台输出没有页头、页脚、导航，可直接嵌进你的软件。</p>

          <label><span>排序值（越小越靠前）</span>
            <input class="ad-input" type="number" name="sort" value="<?= (int) ($doc ? $doc['sort'] : 10) ?>">
          </label>

          <label class="ad-check"><input type="checkbox" name="in_nav" value="1" <?= !$doc || (int) $doc['in_nav'] === 1 ? 'checked' : '' ?>> <span>显示在导航目录中</span></label>
          <label class="ad-check"><input type="checkbox" name="is_home" value="1" <?= ($doc && ((int) $doc['is_home'] === 1 || $homeId === $id)) ? 'checked' : '' ?>> <span>设为站点首页文档</span></label>
        </div>
      </div>

      <div class="ad-card">
        <header class="ad-card-hd"><h2>SEO 与标签</h2></header>
        <div class="ad-card-bd ad-form">
          <label><span>SEO 描述（留空自动截取正文）</span>
            <textarea class="ad-input" name="description" rows="3"><?= e($doc ? $doc['description'] : '') ?></textarea>
          </label>
          <label><span>关键词（逗号分隔）</span>
            <input class="ad-input" type="text" name="keywords" value="<?= e($doc ? $doc['keywords'] : '') ?>">
          </label>
          <label><span>标签（逗号分隔）</span>
            <input class="ad-input" type="text" name="tags" value="<?= e(implode(', ', $tags)) ?>">
          </label>
        </div>
      </div>

      <?php if (!$isNew): ?>
      <div class="ad-card">
        <header class="ad-card-hd"><h2>修订历史</h2><a class="ad-link sm" href="<?= e(admin_url('revisions.php?id=' . $id)) ?>">全部</a></header>
        <div class="ad-card-bd">
          <?php if (!$revisions): ?>
            <p class="ad-muted">还没有历史版本，保存两次后即可回滚。</p>
          <?php else: ?>
            <ul class="ad-rev-list">
              <?php foreach ($revisions as $rev): ?>
                <li>
                  <span><?= e(qn_date((string) $rev['created_at'], 'm-d H:i')) ?></span>
                  <em><?= e($rev['username'] !== '' ? $rev['username'] : '未知') ?></em>
                  <a href="<?= e(admin_url('revisions.php?id=' . $id . '&rev=' . (int) $rev['id'])) ?>">查看</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="ad-card">
        <header class="ad-card-hd"><h2>文档信息</h2></header>
        <div class="ad-card-bd">
          <ul class="ad-kv">
            <li><span>创建时间</span><b><?= e($doc ? qn_date((string) $doc['created_at']) : '—') ?></b></li>
            <li><span>最后更新</span><b><?= e($doc ? qn_date((string) $doc['updated_at']) : '—') ?></b></li>
            <li><span>浏览次数</span><b><?= (int) ($doc['views'] ?? 0) ?></b></li>
            <li><span>文档 ID</span><b><?= $isNew ? '—' : (int) $doc['id'] ?></b></li>
          </ul>
        </div>
      </div>
    </aside>
  </div>
</form>

<div class="ad-modal" id="adMediaModal" hidden>
  <div class="ad-modal-box">
    <header class="ad-modal-hd">
      <h3>插入图片 / 媒体</h3>
      <button type="button" class="ad-icon-btn" id="adMediaClose">×</button>
    </header>
    <div class="ad-modal-bd">
      <div class="ad-upload">
        <input type="file" id="adUploadInput" multiple accept="image/*,.pdf,.zip,.txt,.md">
        <span class="ad-muted">支持图片、PDF、压缩包等，单个文件建议不超过 5MB</span>
      </div>
      <div class="ad-media-list" id="adMediaList"><p class="ad-muted">加载中…</p></div>
    </div>
  </div>
</div>

<div class="ad-modal" id="adLinkModal" hidden>
  <div class="ad-modal-box">
    <header class="ad-modal-hd">
      <h3>插入站内文档链接</h3>
      <button type="button" class="ad-icon-btn" id="adLinkClose">×</button>
    </header>
    <div class="ad-modal-bd">
      <div class="ad-upload">
        <input type="search" class="ad-input" id="adLinkSearch" placeholder="输入标题关键词搜索文档，留空显示最近更新…">
        <button type="button" class="ad-btn sm" id="adLinkSearchBtn">搜索</button>
      </div>
      <div id="adLinkList"><p class="ad-muted">加载中…</p></div>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
