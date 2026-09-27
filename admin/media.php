<?php
/**
 * Qndocs - 媒体库
 */

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $action = (string) post('action');

    if ($action === 'upload') {
        $result = qn_save_upload(isset($_FILES['file']) ? $_FILES['file'] : []);
        flash($result['message'], $result['ok'] ? 'success' : 'error');
    } elseif ($action === 'delete') {
        $id   = (int) post('id');
        $file = DB::one('SELECT * FROM {files} WHERE id = ?', [$id]);
        if ($file) {
            $disk = QN_ROOT . '/' . $file['path'];
            if (is_file($disk)) {
                @unlink($disk);
            }
            DB::delete('files', 'id = ?', [$id]);
            flash('已删除文件「' . $file['name'] . '」。', 'info');
        }
    }
    redirect(admin_url('media.php'));
}

$files = DB::all('SELECT * FROM {files} ORDER BY id DESC LIMIT 200');
$total = (int) DB::val('SELECT COUNT(*) FROM {files}');
$size  = (int) DB::val('SELECT COALESCE(SUM(size),0) FROM {files}');
$dirOk = qn_writable(QN_UPLOAD_DIR);

admin_head('媒体库', 'media.php');
?>

<?php ad_card_open('上传文件'); ?>
  <form method="post" enctype="multipart/form-data" class="ad-upload-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="file" required>
    <button class="ad-btn" type="submit"><?= qn_icon('upload') ?>上传</button>
    <span class="ad-muted">支持 jpg / png / gif / webp / svg / pdf / zip / txt 等，单个文件 ≤ 10MB</span>
  </form>
  <?php if (!$dirOk): ?>
    <div class="ad-alert err mt8">目录 uploads/ 不可写，请设置 755 或以上权限。</div>
  <?php endif; ?>
  <p class="ad-hint">上传后的文件可以直接在编辑器中通过工具栏的「插入图片 / 媒体」按钮引用，地址形如 <code><?= e(qn_url('uploads/202501/xxx.png')) ?></code>。</p>
<?php ad_card_close(); ?>

<?php ad_card_open('文件列表（' . $total . ' 个 · 共 ' . qn_bytes($size) . '）'); ?>
  <?php if (!$files): ?>
    <p class="ad-muted">还没有上传任何文件。</p>
  <?php else: ?>
    <div class="ad-media-grid">
      <?php foreach ($files as $file): ?>
        <?php $isImage = strpos((string) $file['mime'], 'image/') === 0; ?>
        <figure class="ad-media-item">
          <?php if ($isImage && is_file(QN_ROOT . '/' . $file['path'])): ?>
            <img src="<?= e($file['url']) ?>" alt="<?= e($file['name']) ?>" loading="lazy">
          <?php else: ?>
            <span class="ad-file-ext"><?= e(strtoupper(pathinfo((string) $file['name'], PATHINFO_EXTENSION))) ?></span>
          <?php endif; ?>
          <figcaption title="<?= e($file['name']) ?>">
            <?= e($file['name']) ?><em><?= e(qn_bytes($file['size'])) ?> · <?= e(qn_date((string) $file['created_at'], 'Y-m-d')) ?></em>
          </figcaption>
          <span class="ad-media-ops">
            <button type="button" class="ad-mini" data-copy="<?= e($file['url']) ?>">复制地址</button>
            <form method="post" class="ad-inline" data-confirm="确定删除该文件？">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
              <button class="ad-mini danger" type="submit">删除</button>
            </form>
          </span>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php ad_card_close(); ?>

<?php admin_foot(); ?>
