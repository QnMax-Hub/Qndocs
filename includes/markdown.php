<?php
/**
 * Qndocs - 极简 Markdown 解析器（无第三方依赖）
 *
 * 输入先做 HTML 转义再解析，所以 Markdown 模式下天然屏蔽原始 HTML/脚本注入。
 */

function qn_md(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\t", '    ', $text);
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // 1) 先摘出围栏代码块
    $blocks = [];
    $text = preg_replace_callback(
        '/^```[ \t]*([a-zA-Z0-9_+\-]*)[ \t]*\n(.*?)^```[ \t]*$/ms',
        function ($m) use (&$blocks) {
            $lang = $m[1] !== '' ? ' class="language-' . $m[1] . '"' : '';
            $code = rtrim($m[2], "\n");
            $blocks[] = '<pre class="qn-code"><code' . $lang . '>' . $code . '</code></pre>';
            return "\n\x02B" . (count($blocks) - 1) . "\x02\n";
        },
        $text
    );
    if ($text === null) {
        $text = '';
    }

    $lines = explode("\n", $text);
    $count = count($lines);
    $html  = '';
    $list  = null;      // 当前列表类型 ul|ol
    $photo = false;     // 是否在引用块内
    $para  = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . implode("<br>\n", $para) . "</p>\n";
            $para = [];
        }
    };
    $closeAll = function () use (&$para, &$html, &$list, &$photo, $flushPara) {
        $flushPara();
        if ($list !== null) {
            $html .= '</' . $list . ">\n";
            $list = null;
        }
        if ($photo) {
            $html .= "</blockquote>\n";
            $photo = false;
        }
    };

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];

        if (preg_match('/^\x02B(\d+)\x02$/', trim($line), $m)) {
            $closeAll();
            $html .= $blocks[(int) $m[1]] . "\n";
            continue;
        }

        if (trim($line) === '') {
            $closeAll();
            continue;
        }

        // 标题
        if (preg_match('/^(#{1,6})[ \t]+(.+?)[ \t]*#*$/', $line, $m)) {
            $closeAll();
            $level = strlen($m[1]);
            $html .= '<h' . $level . ' id="' . qn_md_anchor($m[2]) . '">' . qn_md_inline($m[2]) . '</h' . $level . ">\n";
            continue;
        }

        // 分隔线
        if (preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})[ \t]*$/', $line)) {
            $closeAll();
            $html .= "<hr>\n";
            continue;
        }

        // 引用
        if (preg_match('/^&gt;[ \t]?(.*)$/', $line, $m)) {
            $flushPara();
            if ($list !== null) {
                $html .= '</' . $list . ">\n";
                $list = null;
            }
            if (!$photo) {
                $html .= "<blockquote>\n";
                $photo = true;
            }
            $html .= '<p>' . qn_md_inline($m[1]) . "</p>\n";
            continue;
        }

        // 表格
        if (strpos($line, '|') !== false
            && isset($lines[$i + 1])
            && strpos($lines[$i + 1], '-') !== false
            && preg_match('/^[\s|:\-]+$/', $lines[$i + 1])) {
            $closeAll();
            $head  = qn_md_row($line);
            $align = qn_md_align($lines[$i + 1]);
            $i++;
            $body = '';
            while (isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '' && strpos($lines[$i + 1], '|') !== false) {
                $i++;
                $body .= '<tr>';
                foreach (qn_md_row($lines[$i]) as $k => $cell) {
                    $style = isset($align[$k]) && $align[$k] !== '' ? ' style="text-align:' . $align[$k] . '"' : '';
                    $body .= '<td' . $style . '>' . qn_md_inline($cell) . '</td>';
                }
                $body .= "</tr>\n";
            }
            $html .= '<div class="qn-table-wrap"><table><thead><tr>';
            foreach ($head as $k => $cell) {
                $style = isset($align[$k]) && $align[$k] !== '' ? ' style="text-align:' . $align[$k] . '"' : '';
                $html .= '<th' . $style . '>' . qn_md_inline($cell) . '</th>';
            }
            $html .= "</tr></thead><tbody>\n" . $body . "</tbody></table></div>\n";
            continue;
        }

        // 列表
        if (preg_match('/^(\s*)([-*+]|\d+[.)])[ \t]+(.*)$/', $line, $m)) {
            $flushPara();
            if ($photo) {
                $html .= "</blockquote>\n";
                $photo = false;
            }
            $want = preg_match('/^\d/', $m[2]) ? 'ol' : 'ul';
            if ($list !== $want) {
                if ($list !== null) {
                    $html .= '</' . $list . ">\n";
                }
                $html .= '<' . $want . ">\n";
                $list = $want;
            }
            $raw = $m[3];
            if (preg_match('/^\[([ xX])\][ \t]+(.*)$/', $raw, $tm)) {
                $checked = strtolower($tm[1]) === 'x' ? ' checked' : '';
                $html .= '<li class="qn-task"><input type="checkbox" disabled' . $checked . '> ' . qn_md_inline($tm[2]) . "</li>\n";
            } else {
                $html .= '<li>' . qn_md_inline($raw) . "</li>\n";
            }
            continue;
        }

        // 普通段落
        $para[] = qn_md_inline($line);
    }
    $closeAll();

    return $html;
}

function qn_md_row(string $line): array
{
    $line = trim($line);
    $line = preg_replace('/^\|/', '', $line);
    $line = preg_replace('/\|$/', '', $line);
    return array_map('trim', explode('|', $line));
}

function qn_md_align(string $line): array
{
    $out = [];
    foreach (qn_md_row($line) as $cell) {
        $left  = strpos($cell, ':') === 0;
        $right = substr($cell, -1) === ':';
        if ($left && $right) {
            $out[] = 'center';
        } elseif ($right) {
            $out[] = 'right';
        } elseif ($left) {
            $out[] = 'left';
        } else {
            $out[] = '';
        }
    }
    return $out;
}

function qn_md_inline(string $text): string
{
    // 行内代码优先占位
    $codes = [];
    $text = (string) preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . trim($m[1]) . '</code>';
        return "\x03C" . (count($codes) - 1) . "\x03";
    }, $text);

    // 图片
    $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
        return '<img src="' . qn_md_url($m[2]) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);

    // 链接
    $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url    = qn_md_url($m[2]);
        $target = preg_match('#^https?://#i', $m[2]) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '"' . $target . '>' . $m[1] . '</a>';
    }, $text);

    // 自动链接
    $text = (string) preg_replace('/&lt;(https?:\/\/[^\s&]+)&gt;/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);

    // 强调
    $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/(?<![a-zA-Z0-9_])__(?=\S)(.+?)(?<=\S)__(?![a-zA-Z0-9_])/s', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);
    $text = (string) preg_replace('/\*(?=\S)([^*\n]+?)(?<=\S)\*/', '<em>$1</em>', $text);
    $text = (string) preg_replace('/(?<![a-zA-Z0-9_])_(?=\S)([^_\n]+?)(?<=\S)_(?![a-zA-Z0-9_])/', '<em>$1</em>', $text);

    // 还原行内代码
    $text = (string) preg_replace_callback('/\x03C(\d+)\x03/', function ($m) use ($codes) {
        return $codes[(int) $m[1]] ?? '';
    }, $text);

    return $text;
}

/** 过滤危险协议，返回可安全放入 HTML 属性的 URL（文本已转义） */
function qn_md_url(string $url): string
{
    $url = trim($url, " \t\n\r<>\"'");
    if ($url === '') {
        return '#';
    }
    if (preg_match('/^\s*(javascript|vbscript|data):/i', $url)) {
        return '#';
    }
    return $url;
}

/** 标题锚点（用于目录跳转） */
function qn_md_anchor(string $text): string
{
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $text);
    $text = preg_replace('/[\s]+/u', '-', trim($text));
    $text = mb_strtolower($text, 'UTF-8');
    return $text === '' ? 'h' : $text;
}
