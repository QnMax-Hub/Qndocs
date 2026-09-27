<?php
/**
 * Qndocs - 样式中心（全站 CSS 统一管理）
 */

require_once __DIR__ . '/_common.php';

$defaults = qn_default_css_vars();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $action = (string) post('action');

    if ($action === 'reset') {
        set_opt('css_vars', json_encode($defaults, JSON_UNESCAPED_UNICODE));
        set_opt('css_custom', '');
        qn_rebuild_static();
        flash('已恢复为默认的「线性蓝」初始样式。');
        redirect(admin_url('style.php'));
    }

    $raw   = (string) post('css_vars');
    $vars  = json_decode($raw, true);
    $clean = [];
    if (is_array($vars)) {
        foreach ($vars as $key => $value) {
            $key = (string) $key;
            if (!preg_match('/^[a-zA-Z0-9\-]{1,40}$/', $key)) {
                continue;
            }
            $value = trim((string) $value);
            $value = str_replace(['{', '}', '<', '>'], '', $value);
            if ($value === '') {
                continue; // 留空＝恢复该变量为默认值，不写入（否则会生成 --primary: ; 导致按钮隐身）
            }
            $clean[$key] = mb_substr($value, 0, 160, 'UTF-8');
        }
    }
    set_opt('css_vars', json_encode($clean, JSON_UNESCAPED_UNICODE));
    set_opt('css_custom', (string) post('css_custom'));

    $theme = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) post('theme'));
    if ($theme !== '' && isset(qn_themes()[$theme])) {
        set_opt('theme', $theme);
    }
    set_opt('doc_width', (string) max(560, min(1400, (int) post('doc_width', 860))));

    $stat = qn_rebuild_static();
    flash('样式已保存并全站生效，重新生成静态页面 ' . $stat['ok'] . ' 个。');
    redirect(admin_url('style.php'));
}

/* ------------------------------------------------------------------ 数据 */

$vars      = array_merge($defaults, qn_css_vars());
$custom    = (string) opt('css_custom', '');
$themes    = qn_themes();
$themeNow  = qn_theme_name();
$docWidth  = (int) opt('doc_width', 860);

$groups = [
    '主色（线性蓝）' => [
        'primary'      => ['主色调', 'color'],
        'primary-dark' => ['深色 / 悬停', 'color'],
        'primary-soft' => ['浅色底纹', 'color'],
    ],
    '文本与背景' => [
        'text'         => ['正文文字', 'color'],
        'text-muted'   => ['次要文字', 'color'],
        'bg'           => ['页面背景', 'color'],
        'bg-soft'      => ['柔和背景', 'color'],
        'border'       => ['线条 / 边框', 'color'],
    ],
    '排版' => [
        'font-size'    => ['正文字号', 'text'],
        'line-height'  => ['行高', 'text'],
        'font-family'  => ['字体族', 'text'],
    ],
    '宽度与圆角' => [
        'content-width' => ['内容宽度（独立页面）', 'text'],
        'radius'       => ['圆角', 'text'],
        'radius-sm'    => ['小圆角', 'text'],
    ],
    '代码块' => [
        'code-bg'      => ['代码底色', 'color'],
        'code-text'    => ['代码文字', 'color'],
    ],
];

$presets = [
    'linear-blue' => ['线性蓝（初始）', ['primary' => '#2563eb', 'primary-dark' => '#1d4ed8', 'primary-soft' => '#e8f0fe']],
    'sky'         => ['天蓝', ['primary' => '#0284c7', 'primary-dark' => '#0369a1', 'primary-soft' => '#e0f2fe']],
    'indigo'      => ['靛蓝', ['primary' => '#4f46e5', 'primary-dark' => '#4338ca', 'primary-soft' => '#e9e7fd']],
    'navy'        => ['藏青', ['primary' => '#1e3a8a', 'primary-dark' => '#1e40af', 'primary-soft' => '#e5eaf6']],
    'slate'       => ['石墨蓝', ['primary' => '#334155', 'primary-dark' => '#1e293b', 'primary-soft' => '#e9eef5']],
];

admin_head('样式中心', 'style.php');
?>

<form method="post" class="ad-style" id="adStyleForm" action="<?= e(admin_url('style.php')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="css_vars" id="adCssVars" value="<?= e(json_encode($vars, JSON_UNESCAPED_UNICODE)) ?>">

  <div class="ad-style-grid">
    <div class="ad-style-form">

      <section class="ad-card">
        <header class="ad-card-hd"><h2>主题与配色</h2></header>
        <div class="ad-card-bd ad-form">
          <label><span>前台主题</span>
            <select name="theme">
              <?php foreach ($themes as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= $key === $themeNow ? 'selected' : '' ?>>
                  <?= e($meta['title']) ?>（<?= e($key) ?>）
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="ad-presets">
            <span class="ad-label">配色预设</span>
            <div class="ad-preset-row">
              <?php foreach ($presets as $key => $preset): ?>
                <button type="button" class="ad-preset" data-preset='<?= e(json_encode($preset[1], JSON_UNESCAPED_UNICODE)) ?>'>
                  <i style="background:<?= e($preset[1]['primary']) ?>"></i><?= e($preset[0]) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

      <?php foreach ($groups as $groupName => $fields): ?>
        <section class="ad-card">
          <header class="ad-card-hd"><h2><?= e($groupName) ?></h2></header>
          <div class="ad-card-bd ad-vars">
            <?php foreach ($fields as $key => $meta): ?>
              <div class="ad-var">
                <label for="var-<?= e($key) ?>"><?= e($meta[0]) ?></label>
                <div class="ad-var-input">
                  <?php if ($meta[1] === 'color'): ?>
                    <input type="color" id="var-<?= e($key) ?>" data-var="<?= e($key) ?>" value="<?= e($vars[$key] ?? '#000000') ?>">
                  <?php endif; ?>
                  <input type="text" class="ad-input sm" data-var="<?= e($key) ?>"
                         value="<?= e($vars[$key] ?? '') ?>" spellcheck="false">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <section class="ad-card">
        <header class="ad-card-hd"><h2>自定义 CSS</h2></header>
        <div class="ad-card-bd ad-form">
          <p class="ad-hint">这里的内容会追加在主题样式之后，全站所有页面统一生效。可以针对任意选择器调整细节。</p>
          <textarea class="ad-textarea code" name="css_custom" id="adCustomCss" rows="12" spellcheck="false"
                    placeholder="例如：&#10;.qn-content table th { background: #eef4ff; }&#10;.qn-header { box-shadow: 0 1px 0 rgba(37,99,235,.12); }"><?= e($custom) ?></textarea>
        </div>
      </section>

      <div class="ad-sticky-actions">
        <button class="ad-btn" type="submit"><?= qn_icon('save') ?>保存样式并全站应用</button>
        <button class="ad-btn ghost" type="submit" name="action" value="reset"
                data-confirm="确定恢复默认的「线性蓝」样式吗？当前自定义变量与 CSS 将被清空。">恢复默认</button>
      </div>
    </div>

    <div class="ad-style-preview">
      <div class="ad-preview-bar">
        <span>前台效果预览</span>
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
      <iframe id="adPreviewFrame" name="adPreview" class="ad-frame tall" title="样式预览"></iframe>
      <p class="ad-hint">预览使用真实主题渲染（含侧边导航与正文排版）。点击「保存样式」后全站立即生效。</p>
    </div>
  </div>
</form>

<!-- 隐藏的预览提交表单 -->
<form method="post" id="adPreviewForm" action="<?= e(admin_url('preview.php')) ?>" target="adPreview" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="style_preview" value="1">
  <input type="hidden" name="css_vars" value="">
  <input type="hidden" name="css_custom" value="">
  <input type="hidden" name="theme" value="<?= e($themeNow) ?>">
  <input type="hidden" name="doc_width" value="<?= $docWidth ?>">
</form>

<?php admin_foot(); ?>
