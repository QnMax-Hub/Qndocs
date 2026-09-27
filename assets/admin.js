/**
 * Qndocs 后台脚本
 *  · 通用交互（侧栏、确认、复制、菜单、伪静态检测…）
 *  · 富文本编辑器（所见即所得，输出纯 HTML）
 *  · 样式中心（CSS 变量 + 实时预览）
 */
(function () {
  'use strict';

  var tokenEl = document.querySelector('input[name="_token"]');
  var CSRF = tokenEl ? tokenEl.value : '';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function escapeHtml(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function escapeAttr(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* ================================================================= 通用 */

  document.addEventListener('DOMContentLoaded', function () {
    var burger = $('#adBurger');
    if (burger) {
      burger.addEventListener('click', function () { document.body.classList.toggle('ad-side-open'); });
    }

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.dataset && form.dataset.confirm) {
        if (!window.confirm(form.dataset.confirm)) {
          e.preventDefault();
          e.stopPropagation();
        }
      }
    });

    $$('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy');
        var done = function () {
          var old = btn.textContent;
          btn.textContent = '已复制';
          setTimeout(function () { btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, done);
        } else {
          var ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); } catch (err) {}
          document.body.removeChild(ta);
          done();
        }
      });
    });

    $$('[data-menu]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var menu = btn.parentNode.querySelector('.ad-menu');
        $$('.ad-menu.on').forEach(function (m) { if (m !== menu) { m.classList.remove('on'); } });
        if (menu) { menu.classList.toggle('on'); }
      });
    });
    document.addEventListener('click', function () {
      $$('.ad-menu.on').forEach(function (m) { m.classList.remove('on'); });
    });

    $$('[data-prompt-password]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = $('#adPassForm' + btn.getAttribute('data-prompt-password'));
        if (!form) { return; }
        form.hidden = !form.hidden;
        if (!form.hidden) {
          var input = form.querySelector('input[type="password"]');
          if (input) { input.focus(); }
        }
      });
    });

    var filter = $('#adDocSearch');
    if (filter) {
      filter.addEventListener('input', function () {
        var kw = filter.value.trim().toLowerCase();
        $$('#adDocTable tbody tr').forEach(function (tr) {
          var hay = tr.getAttribute('data-search') || '';
          tr.style.display = (kw === '' || hay.indexOf(kw) > -1) ? '' : 'none';
        });
      });
    }

    var probe = $('#adRewriteTest');
    if (probe) {
      probe.addEventListener('click', function () {
        var box = $('#adRewriteResult');
        if (box) { box.innerHTML = '<span class="ad-muted">检测中…</span>'; }
        fetch(probe.getAttribute('data-probe'), { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.text(); })
          .then(function (t) {
            var ok = t.indexOf('Qndocs') !== -1 || t.indexOf('页面不存在') !== -1;
            if (box) {
              box.innerHTML = ok
                ? '<span class="ad-badge ok">伪静态已生效</span>'
                : '<span class="ad-badge warn">未生效：请按说明配置重写规则，或关闭伪静态开关</span>';
            }
          })
          .catch(function () { if (box) { box.textContent = '检测失败，请检查网络后重试'; } });
      });
    }

    $$('.qn-toast').forEach(function (toast) {
      setTimeout(function () {
        toast.style.transition = 'opacity .3s, transform .3s';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-6px)';
        setTimeout(function () { toast.remove(); }, 320);
      }, 4200);
    });

    initEditor();
    initStyleCenter();
  });

  /* ================================================= 富文本内容清理 */

  var ALLOWED_TAGS = ('P,BR,DIV,SPAN,B,STRONG,I,EM,U,S,STRIKE,DEL,H1,H2,H3,H4,H5,H6,UL,OL,LI,'
    + 'BLOCKQUOTE,PRE,CODE,A,IMG,TABLE,THEAD,TBODY,TR,TH,TD,HR,FIGURE,FIGCAPTION,SECTION,ARTICLE,SMALL').split(',');
  var ALLOWED_ATTR = ['href', 'src', 'alt', 'title', 'colspan', 'rowspan', 'target', 'rel', 'class', 'style'];

  function sanitizeHtml(html) {
    var doc;
    try {
      doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
    } catch (e) {
      return escapeHtml(html);
    }
    Array.prototype.slice.call(doc.body.querySelectorAll('*')).forEach(function (el) {
      if (ALLOWED_TAGS.indexOf(el.tagName) === -1) {
        el.parentNode.replaceChild(doc.createTextNode(el.textContent || ''), el);
        return;
      }
      Array.prototype.slice.call(el.attributes).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        if (ALLOWED_ATTR.indexOf(name) === -1 || name.indexOf('on') === 0) {
          el.removeAttribute(attr.name);
          return;
        }
        if ((name === 'href' || name === 'src') && /^\s*(javascript|vbscript|data):/i.test(attr.value)) {
          el.removeAttribute(attr.name);
        }
      });
      if (el.hasAttribute('style')) {
        el.setAttribute('style', el.getAttribute('style').replace(/expression|javascript:|url\s*\(/gi, ''));
      }
    });
    return doc.body.innerHTML;
  }

  /* ==================================================== 富文本编辑器 */

  function initEditor() {
    var form = $('#adEditorForm');
    var editor = $('#rtEditor');
    var hidden = $('#adContent');
    if (!form || !editor || !hidden) { return; }

    var rtWrap = $('#rtWrap');
    var source = $('#rtSource');
    var panes = $('#adPanes');
    var frame = $('#adPreviewFrame');
    var sourceMode = false;

    /* ------------------------------------------- 内容类型：富文本 / 纯文本 */

    var typeInput = form.querySelector('input[name="type"]');
    var plainWrap = $('#rtPlainWrap');
    var plainInput = $('#rtPlain');
    var currentType = typeInput ? (typeInput.value === 'text' ? 'text' : 'html') : 'html';

    function setContentType(t) {
      currentType = (t === 'text') ? 'text' : 'html';
      if (typeInput) { typeInput.value = currentType; }
      if (rtWrap) { rtWrap.hidden = currentType !== 'html'; }
      if (plainWrap) { plainWrap.hidden = currentType !== 'text'; }
      $$('#adTypeSeg button').forEach(function (b) {
        b.classList.toggle('on', b.getAttribute('data-ctype') === currentType);
      });
      if (currentType === 'text' && plainInput) { plainInput.focus(); }
    }

    // 初始内容：隐藏字段里已经是服务端准备好的 HTML
    hidden.value = editor.innerHTML;
    try { document.execCommand('styleWithCSS', false, true); } catch (e) {}

    function sync() {
      if (currentType === 'text') {
        if (plainInput) { hidden.value = plainInput.value; }
        return;
      }
      hidden.value = editor.innerHTML;
    }

    function exec(cmd, value) {
      editor.focus();
      try { document.execCommand(cmd, false, value === undefined ? null : value); } catch (e) {}
      sync();
    }

    function trimEmpty(html) {
      return html
        .replace(/(<p>(<br\s*\/?>|\s|&nbsp;)*<\/p>|<div>(<br\s*\/?>|\s|&nbsp;)*<\/div>|\s)+$/gi, '')
        .replace(/^\s+/, '');
    }

    /** 提交前把编辑器内容写进隐藏字段（表单与预览共用） */
    function prepareContent() {
      if (currentType === 'text') {
        hidden.value = plainInput ? plainInput.value : '';
        return hidden.value;
      }
      if (sourceMode && source) {
        editor.innerHTML = sanitizeHtml(source.value);
      }
      var html = editor.innerHTML;
      var plain = html.replace(/<br\s*\/?>/gi, '').replace(/&nbsp;/gi, '').replace(/<[^>]+>/g, '').trim();
      hidden.value = plain === '' ? '' : trimEmpty(html);
      return hidden.value;
    }

    /* ---------------------------------------------------- 工具栏 */

    $$('#rtToolbar [data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function () { exec(btn.getAttribute('data-cmd')); });
    });

    $$('#rtToolbar [data-block]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        exec('formatBlock', '<' + btn.getAttribute('data-block') + '>');
      });
    });

    $$('#rtToolbar [data-color]').forEach(function (btn) {
      btn.addEventListener('click', function () { exec('foreColor', btn.getAttribute('data-color')); });
    });

    /* ------------------------------------------------ 表格行列操作 */

    function currentCell() {
      var sel = window.getSelection();
      if (!sel || !sel.rangeCount) { return null; }
      var node = sel.getRangeAt(0).startContainer;
      while (node && node !== editor) {
        if (node.nodeType === 1 && (node.tagName === 'TD' || node.tagName === 'TH')) { return node; }
        node = node.parentNode;
      }
      return null;
    }

    function tableRows(table) {
      return Array.prototype.slice.call(table.querySelectorAll('tr'));
    }

    function tableOp(op) {
      var cell = currentCell();
      if (!cell) { window.alert('请先把光标点到表格里，再执行这个操作。'); return; }
      var table = cell;
      while (table && table !== editor && table.tagName !== 'TABLE') { table = table.parentNode; }
      if (!table || table === editor) { window.alert('请先把光标点到表格里。'); return; }

      var tr    = cell.parentNode;
      var group = tr.parentNode;
      var index = Array.prototype.indexOf.call(tr.children, cell);
      var rows  = tableRows(table);

      if (op === 'rowAdd') {
        var newTr = tr.cloneNode(true);
        Array.prototype.forEach.call(newTr.children, function (c) { c.innerHTML = ''; });
        group.insertBefore(newTr, tr.nextSibling);
      } else if (op === 'rowDel') {
        if (rows.length <= 1) { window.alert('表格至少保留一行。'); return; }
        group.removeChild(tr);
      } else if (op === 'colAdd') {
        rows.forEach(function (row) {
          var cells = row.children;
          if (index >= cells.length) { return; }
          var newCell = document.createElement(cells[index].tagName);
          cells[index].parentNode.insertBefore(newCell, cells[index].nextSibling);
        });
      } else if (op === 'colDel') {
        if (rows[0] && rows[0].children.length <= 1) { window.alert('表格至少保留一列。'); return; }
        rows.forEach(function (row) {
          if (index < row.children.length) { row.removeChild(row.children[index]); }
        });
      }
      sync();
    }

    $$('#rtToolbar [data-table]').forEach(function (btn) {
      btn.addEventListener('click', function () { tableOp(btn.getAttribute('data-table')); });
    });

    /* -------------------------------------------------- 组件插入 */

    var BLOCK_HTML = {
      alert: '<div class="qn-alert">这里是一条提示内容。</div><p><br></p>',
      cards: '<div class="qn-cards"><div class="qn-card"><h3>卡片标题</h3><p>卡片说明文字。</p></div>'
        + '<div class="qn-card"><h3>卡片标题</h3><p>卡片说明文字。</p></div></div><p><br></p>',
      hero: '<section class="qn-hero"><span class="qn-badge">标签</span><h1>标题</h1>'
        + '<p class="qn-hero-desc">这里是一段描述文字。</p>'
        + '<p class="qn-hero-actions"><a class="qn-btn" href="#">主要按钮</a></p></section><p><br></p>',
      btn: '<a class="qn-btn" href="#">按钮文字</a>'
    };

    $$('#rtToolbar [data-block-html]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-block-html');
        if (BLOCK_HTML[key]) { exec('insertHTML', BLOCK_HTML[key]); }
      });
    });

    var blockSelect = $('#rtBlock');
    if (blockSelect) {
      blockSelect.addEventListener('change', function () {
        exec('formatBlock', '<' + blockSelect.value + '>');
      });
    }

    function insertTable() {
      var row = '<tr><td>内容</td><td>内容</td><td>内容</td></tr>';
      exec('insertHTML', '<table><thead><tr><th>标题</th><th>标题</th><th>标题</th></tr></thead>'
        + '<tbody>' + row + row + row + '</tbody></table><p><br></p>');
    }

    function toggleSource() {
      if (!source) { return; }
      if (!sourceMode) {
        source.value = editor.innerHTML;
        source.hidden = false;
        editor.hidden = true;
        sourceMode = true;
      } else {
        editor.innerHTML = sanitizeHtml(source.value);
        editor.hidden = false;
        source.hidden = true;
        sourceMode = false;
        sync();
      }
    }

    function toggleFullscreen() {
      if (!rtWrap) { return; }
      rtWrap.classList.toggle('rt-fullscreen');
      document.body.classList.toggle('rt-lock', rtWrap.classList.contains('rt-fullscreen'));
    }

    /* ---------------------------------------------- 图片 / 链接插入 */

    function insertImage(url, alt, name) {
      exec('insertHTML', '<img src="' + escapeAttr(url) + '" alt="' + escapeAttr(alt || name || '') + '">');
    }

    function insertLink(url, title) {
      var sel = window.getSelection();
      var selected = sel ? String(sel.toString()) : '';
      editor.focus();
      if (selected !== '') {
        exec('createLink', url);
      } else {
        exec('insertHTML', '<a href="' + escapeAttr(url) + '">' + escapeHtml(title || url) + '</a>');
      }
    }

    var modal = $('#adMediaModal');
    var list = $('#adMediaList');
    var uploadInput = $('#adUploadInput');
    var linkModal = $('#adLinkModal');
    var linkList = $('#adLinkList');
    var linkSearch = $('#adLinkSearch');

    function loadMedia() {
      if (!list) { return; }
      list.innerHTML = '<p class="ad-muted">加载中…</p>';
      fetch('ajax.php?action=media_list', {
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          list.innerHTML = (res.ok && res.data && res.data.html) ? res.data.html : '<p class="ad-muted">加载失败</p>';
          bindMediaItems();
        })
        .catch(function () { list.innerHTML = '<p class="ad-muted">加载失败</p>'; });
    }

    function bindMediaItems() {
      $$('[data-pick]', list).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var fig = btn.closest('.ad-media-item');
          insertImage(btn.getAttribute('data-pick'), '', fig ? (fig.getAttribute('data-name') || '') : '');
          closeModal();
        });
      });
      $$('[data-del]', list).forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!window.confirm('确定从媒体库删除该文件？')) { return; }
          var body = new FormData();
          body.append('action', 'media_delete');
          body.append('id', btn.getAttribute('data-del'));
          fetch('ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: body
          }).then(function () { loadMedia(); });
        });
      });
    }

    function openModal() { if (modal) { modal.hidden = false; modal.classList.add('on'); loadMedia(); } }
    function closeModal() { if (modal) { modal.classList.remove('on'); modal.hidden = true; } }

    function loadLinks(keyword) {
      if (!linkList) { return; }
      linkList.innerHTML = '<p class="ad-muted">加载中…</p>';
      fetch('ajax.php?action=doc_search&q=' + encodeURIComponent(keyword || ''), {
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var items = (res.ok && res.data && res.data.items) ? res.data.items : [];
          if (!items.length) { linkList.innerHTML = '<p class="ad-muted">没有匹配的文档。</p>'; return; }
          var html = '<ul class="ad-link-list">';
          items.forEach(function (item) {
            html += '<li><button type="button" data-link-url="' + escapeAttr(item.url) + '" data-link-title="'
              + escapeAttr(item.title) + '">' + escapeHtml(item.title) + '</button><em>' + escapeHtml(item.url) + '</em></li>';
          });
          linkList.innerHTML = html + '</ul>';
          $$('[data-link-url]', linkList).forEach(function (btn) {
            btn.addEventListener('click', function () {
              insertLink(btn.getAttribute('data-link-url'), btn.getAttribute('data-link-title'));
              closeLinkModal();
            });
          });
        })
        .catch(function () { linkList.innerHTML = '<p class="ad-muted">加载失败，请刷新页面重试。</p>'; });
    }

    function openLinkModal() {
      if (!linkModal) { return; }
      linkModal.hidden = false;
      linkModal.classList.add('on');
      if (linkSearch) { linkSearch.value = ''; linkSearch.focus(); }
      loadLinks('');
    }
    function closeLinkModal() {
      if (linkModal) { linkModal.classList.remove('on'); linkModal.hidden = true; }
    }

    function askLink() {
      var sel = window.getSelection();
      var selected = sel ? String(sel.toString()) : '';
      var url = window.prompt(selected ? '为选中的文字添加链接地址：' : '请输入链接地址（站内路径或完整网址）：', 'https://');
      if (url === null || url.trim() === '') { return; }
      insertLink(url.trim(), selected || url.trim());
    }

    $$('#rtToolbar [data-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-action');
        if (action === 'image') { openModal(); }
        else if (action === 'link') { askLink(); }
        else if (action === 'linkPick') { openLinkModal(); }
        else if (action === 'table') { insertTable(); }
        else if (action === 'hr') { exec('insertHTML', '<hr><p><br></p>'); }
        else if (action === 'source') { toggleSource(); }
        else if (action === 'fullscreen') { toggleFullscreen(); }
      });
    });

    if (modal) { modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } }); }
    if (linkModal) { linkModal.addEventListener('click', function (e) { if (e.target === linkModal) { closeLinkModal(); } }); }
    var mediaClose = $('#adMediaClose');
    if (mediaClose) { mediaClose.addEventListener('click', closeModal); }
    var linkClose = $('#adLinkClose');
    if (linkClose) { linkClose.addEventListener('click', closeLinkModal); }

    if (uploadInput) {
      uploadInput.addEventListener('change', function () {
        if (!uploadInput.files.length) { return; }
        var files = Array.prototype.slice.call(uploadInput.files);
        var done = 0;
        files.forEach(function (file) {
          var body = new FormData();
          body.append('action', 'upload');
          body.append('file', file);
          fetch('ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: body
          })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              done++;
              if (res.ok && res.data && res.data.url) {
                if (done === files.length || files.length === 1) { insertImage(res.data.url, '', res.data.name); }
              } else {
                window.alert(res.message || '上传失败');
              }
              if (done === files.length) { loadMedia(); }
            });
        });
        uploadInput.value = '';
      });
    }

    var linkSearchBtn = $('#adLinkSearchBtn');
    if (linkSearchBtn) {
      linkSearchBtn.addEventListener('click', function () { loadLinks(linkSearch ? linkSearch.value.trim() : ''); });
    }
    if (linkSearch) {
      linkSearch.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); loadLinks(linkSearch.value.trim()); }
      });
    }

    /* -------------------------------------------------- 视图与预览 */

    // 内容类型切换
    $$('#adTypeSeg button').forEach(function (b) {
      b.addEventListener('click', function () { setContentType(b.getAttribute('data-ctype')); });
    });
    setContentType(currentType);
    if (plainInput) { plainInput.addEventListener('input', sync); }

    $$('#adViewSeg button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        $$('#adViewSeg button').forEach(function (b) { b.classList.remove('on'); });
        btn.classList.add('on');
        var view = btn.getAttribute('data-view');
        panes.setAttribute('data-view', view);
        if (view !== 'edit') { submitPreview(); }
      });
    });

    function submitPreview() {
      prepareContent();
      var f = document.createElement('form');
      f.method = 'post';
      f.action = 'preview.php';
      f.target = 'adPreview';
      f.style.display = 'none';
      ['_token', 'id', 'title', 'type', 'content'].forEach(function (name) {
        var el = form.querySelector('[name="' + name + '"]');
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = (el && el.value !== undefined) ? el.value : (name === 'id' ? (form.dataset.id || '') : '');
        f.appendChild(input);
      });
      document.body.appendChild(f);
      f.submit();
      setTimeout(function () { f.remove(); }, 1500);
    }

    var refreshBtn = $('#adRefreshPreview');
    if (refreshBtn) { refreshBtn.addEventListener('click', submitPreview); }

    var widthSelect = $('#adPreviewWidth');
    if (widthSelect && frame) {
      widthSelect.addEventListener('change', function () { frame.style.maxWidth = widthSelect.value; });
    }

    /* ------------------------------------------- Slug / 状态 / 快捷键 */

    var titleInput = form.querySelector('input[name="title"]');
    var slugInput = $('#adSlug');
    var slugPreview = $('#adSlugPreview');
    var slugAuto = $('#adSlugAuto');

    function toSlug(text) {
      return (text || '').trim()
        .replace(/[\s\u3000]+/g, '-')
        .replace(/[^\w\u4e00-\u9fa5\-.]/g, '')
        .replace(/-{2,}/g, '-')
        .replace(/^[-_.]+|[-_.]+$/g, '')
        .slice(0, 80);
    }
    function updateSlugPreview() {
      if (!slugPreview) { return; }
      var value = (slugInput && slugInput.value.trim()) ? slugInput.value.trim() : toSlug(titleInput ? titleInput.value : '');
      slugPreview.textContent = '/' + (value || 'doc') + '.html';
    }
    if (slugInput) { slugInput.addEventListener('input', updateSlugPreview); }
    if (titleInput) { titleInput.addEventListener('input', updateSlugPreview); }
    if (slugAuto && slugInput && titleInput) {
      slugAuto.addEventListener('click', function () {
        slugInput.value = toSlug(titleInput.value);
        updateSlugPreview();
      });
    }
    updateSlugPreview();

    var statusSelect = $('#adStatus');
    var pwWrap = $('#adPasswordWrap');
    function togglePw() {
      if (!statusSelect || !pwWrap) { return; }
      pwWrap.style.display = statusSelect.value === 'password' ? '' : 'none';
    }
    if (statusSelect) { statusSelect.addEventListener('change', togglePw); }
    togglePw();

    editor.addEventListener('input', sync);
    editor.addEventListener('blur', sync);

    editor.addEventListener('paste', function (e) {
      var cb = e.clipboardData || window.clipboardData;
      if (!cb) { return; }
      var html = cb.getData('text/html');
      var text = cb.getData('text/plain');
      e.preventDefault();
      if (html) { exec('insertHTML', sanitizeHtml(html)); }
      else { exec('insertText', text); }
    });

    // 拖入图片自动上传
    editor.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) { return; }
      e.preventDefault();
      Array.prototype.slice.call(dt.files).forEach(function (file) {
        if (file.type.indexOf('image/') !== 0) { return; }
        var body = new FormData();
        body.append('action', 'upload');
        body.append('file', file);
        fetch('ajax.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
          body: body
        })
          .then(function (r) { return r.json(); })
          .then(function (res) { if (res.ok && res.data) { insertImage(res.data.url, '', res.data.name); } });
      });
    });

    form.addEventListener('submit', function () { prepareContent(); });

    document.addEventListener('keydown', function (e) {
      var meta = e.ctrlKey || e.metaKey;
      if (!meta) { return; }
      if (e.key === 's' || e.key === 'S') { e.preventDefault(); prepareContent(); form.submit(); }
      else if (e.key === 'Enter') { e.preventDefault(); submitPreview(); }
    });

    window.qnSubmitPreview = submitPreview;
  }

  /* ======================================================= 样式中心 */

  function initStyleCenter() {
    var varsInput = $('#adCssVars');
    if (!varsInput) { return; }

    var previewForm = $('#adPreviewForm');
    var timer = null;

    function readVars() {
      var out = {};
      $$('[data-var]').forEach(function (input) {
        var key = input.getAttribute('data-var');
        var value = input.value.trim();
        if (value !== '') { out[key] = value; }
      });
      return out;
    }

    function syncInputs(key, value) {
      $$('[data-var="' + key + '"]').forEach(function (input) {
        if (input.value !== value) { input.value = value; }
      });
    }

    function refreshPreview() {
      if (!previewForm) { return; }
      previewForm.querySelector('[name="css_vars"]').value = JSON.stringify(readVars());
      previewForm.querySelector('[name="css_custom"]').value = ($('#adCustomCss') || {}).value || '';
      previewForm.submit();
    }

    function schedule() {
      varsInput.value = JSON.stringify(readVars());
      clearTimeout(timer);
      timer = setTimeout(refreshPreview, 420);
    }

    $$('[data-var]').forEach(function (input) {
      input.addEventListener('input', function () {
        syncInputs(input.getAttribute('data-var'), input.value);
        schedule();
      });
      input.addEventListener('change', function () {
        syncInputs(input.getAttribute('data-var'), input.value);
        schedule();
      });
    });

    var custom = $('#adCustomCss');
    if (custom) { custom.addEventListener('input', schedule); }

    $$('.ad-preset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var preset;
        try { preset = JSON.parse(btn.getAttribute('data-preset')); } catch (e) { return; }
        Object.keys(preset).forEach(function (key) { syncInputs(key, preset[key]); });
        schedule();
      });
    });

    var refreshBtn = $('#adRefreshPreview');
    if (refreshBtn) { refreshBtn.addEventListener('click', refreshPreview); }

    var frame = $('#adPreviewFrame');
    var widthSelect = $('#adPreviewWidth');
    if (widthSelect && frame) {
      widthSelect.addEventListener('change', function () { frame.style.maxWidth = widthSelect.value; });
    }

    var styleForm = $('#adStyleForm');
    if (styleForm) {
      styleForm.addEventListener('submit', function () { varsInput.value = JSON.stringify(readVars()); });
    }

    refreshPreview();
  }
})();
