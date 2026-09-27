<?php
/**
 * Qndocs - 实时预览
 *   · 编辑器：POST 标题/类型/正文，输出与前台一致的完整页面
 *   · 样式中心：POST style_preview，用临时 CSS 变量渲染示例文档
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_install();
require_login();

header('Content-Type: text/html; charset=utf-8');

if (!csrf_verify()) {
    http_response_code(419);
    exit('预览会话已过期，请返回后台刷新页面。');
}

// 确保选项缓存已加载（样式预览需要在内存里临时覆盖）
if (!isset($GLOBALS['QN_OPTIONS'])) {
    $GLOBALS['QN_OPTIONS'] = qn_load_options();
}

if (isset($_POST['style_preview'])) {
    if (isset($_POST['css_vars'])) {
        $GLOBALS['QN_OPTIONS']['css_vars'] = (string) $_POST['css_vars'];
    }
    if (isset($_POST['css_custom'])) {
        $GLOBALS['QN_OPTIONS']['css_custom'] = (string) $_POST['css_custom'];
    }
    if (!empty($_POST['theme'])) {
        $GLOBALS['QN_OPTIONS']['theme'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $_POST['theme']);
    }
    if (isset($_POST['doc_width'])) {
        $GLOBALS['QN_OPTIONS']['doc_width'] = (string) max(560, min(1400, (int) $_POST['doc_width']));
    }
}

$type    = post('type') === 'text' ? 'text' : 'html';
$content = (string) post('content');
$title   = trim((string) post('title'));
$title   = $title === '' ? '未命名文档' : $title;
$id      = (int) post('id');

if (isset($_POST['style_preview'])) {
    $type    = 'html';
    $title   = '样式预览示例文档';
    $content = qn_preview_sample();
}

$doc = ($id > 0 && !isset($_POST['style_preview'])) ? DB::one('SELECT * FROM {docs} WHERE id = ?', [$id]) : null;
if ($doc) {
    $doc['title']   = $title;
    $doc['type']    = $type;
    $doc['content'] = $content;
} else {
    $doc = [
        'id'          => 0,
        'parent_id'   => 0,
        'title'       => $title,
        'slug'        => 'preview',
        'type'        => $type,
        'content'     => $content,
        'description' => '',
        'keywords'    => '',
        'sort'        => 0,
        'status'      => 'public',
        'password'    => '',
        'is_home'     => 0,
        'in_nav'      => 1,
        'views'       => 0,
        'created_at'  => qn_now(),
        'updated_at'  => qn_now(),
        'tags'        => ['预览'],
    ];
}
$doc['tags'] = $doc['tags'] ?? qn_doc_tags($doc);

echo qn_render_page(qn_page_context($doc, [
    'mode'     => 'doc',
    'is_login' => true,
]));

/** 样式预览用的示例内容 */
function qn_preview_sample(): string
{
    return <<<'HTML'
<section class="qn-hero">
  <span class="qn-badge">样式预览</span>
  <h1>线性蓝文档主题</h1>
  <p class="qn-hero-desc">这是用于预览主题效果与自定义 CSS 的示例文档，覆盖标题、正文、列表、表格、代码块、引用与卡片等常用排版元素。</p>
  <p class="qn-hero-actions"><a class="qn-btn" href="#">主要按钮</a><a class="qn-btn qn-btn-ghost" href="#">次要按钮</a></p>
</section>

<h2>正文排版</h2>
<p>Qndocs 的文档本质上是一个 HTML 文件：后台编辑内容，前台统一套用主题样式输出。修改样式中心的任何一个变量，全站页面都会同步变化。</p>
<p>这是一段包含 <strong>加粗强调</strong>、<em>斜体</em>、<a href="#">文字链接</a> 与 <code>行内代码</code> 的段落，用来观察文字与主色之间的关系。</p>

<h2>列表与表格</h2>
<ul>
  <li>无序列表项，缩进与行距保持线性风格</li>
  <li>第二项，用于检查圆点与文字的对齐</li>
</ul>
<ol>
  <li>有序列表第一项</li>
  <li>有序列表第二项</li>
</ol>
<table>
  <thead><tr><th>变量</th><th>作用</th></tr></thead>
  <tbody>
    <tr><td><code>--primary</code></td><td>主色，用于链接、按钮、强调线</td></tr>
    <tr><td><code>--radius</code></td><td>卡片与容器圆角</td></tr>
    <tr><td><code>--content-width</code></td><td>正文最大阅读宽度</td></tr>
  </tbody>
</table>

<h3>代码与引用</h3>
<pre class="qn-code"><code>// 文档保存后生成静态 HTML 页面
$html = qn_render_page(qn_page_context($doc));
file_put_contents(QN_DATA . '/pages/' . $doc['slug'] . '.html', $html);</code></pre>
<blockquote><p>引用块使用浅色底纹与主色左边线，用于提示与注意事项。</p></blockquote>

<h3>卡片组件</h3>
<div class="qn-cards">
  <div class="qn-card"><h3>样式统一</h3><p>所有页面共用同一份全局 CSS。</p></div>
  <div class="qn-card"><h3>实时预览</h3><p>调整变量后立即看到效果。</p></div>
  <div class="qn-card"><h3>一键恢复</h3><p>随时回到线性蓝初始配色。</p></div>
</div>
HTML;
}
