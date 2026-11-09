<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 极简 Markdown 渲染器（覆盖 API 文档用到的语法：标题、段落、列表、代码块、
 * 行内代码、表格、引用、粗体、链接、分隔线）。输出已转义，可安全用于 HTML。
 */
final class Markdown
{
    public static function render(string $markdown): string
    {
        // 注意：不能用 \R —— 非 UTF 模式下 0x85 会被当作换行，而中文字符里常含该字节
        $lines = preg_split('/\r\n|\r|\n/', str_replace("\t", '    ', $markdown)) ?: [];
        $html = [];
        $inCode = false;
        $codeLang = '';
        $codeBuffer = [];
        $inTable = false;
        $tableBuffer = [];
        $listType = null;

        $closeList = static function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html[] = $listType === 'ul' ? '</ul>' : '</ol>';
                $listType = null;
            }
        };

        $flushTable = static function () use (&$inTable, &$tableBuffer, &$html): void {
            if (!$inTable) {
                return;
            }
            $inTable = false;
            $rows = $tableBuffer;
            $tableBuffer = [];
            if ($rows === []) {
                return;
            }
            $head = array_shift($rows);
            // 跳过分隔行
            if ($rows !== [] && preg_match('/^[\s|:\-]+$/', $rows[0])) {
                array_shift($rows);
            }
            $out = '<div class="md-table"><table><thead><tr>';
            foreach (self::cells($head) as $cell) {
                $out .= '<th>' . self::inline($cell) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $out .= '<tr>';
                foreach (self::cells($row) as $cell) {
                    $out .= '<td>' . self::inline($cell) . '</td>';
                }
                $out .= '</tr>';
            }
            $out .= '</tbody></table></div>';
            $html[] = $out;
        };

        foreach ($lines as $line) {
            // 代码块
            if (preg_match('/^```(\w*)/', $line, $m)) {
                if ($inCode) {
                    $html[] = '<pre class="md-code"><code class="lang-' . View::e($codeLang) . '">'
                        . View::e(implode("\n", $codeBuffer)) . '</code></pre>';
                    $codeBuffer = [];
                    $inCode = false;
                    $codeLang = '';
                } else {
                    $flushTable();
                    $closeList();
                    $inCode = true;
                    $codeLang = $m[1];
                }
                continue;
            }
            if ($inCode) {
                $codeBuffer[] = $line;
                continue;
            }

            // 表格
            if (str_contains($line, '|') && preg_match('/^\s*\|.*\|\s*$/', $line)) {
                $closeList();
                $inTable = true;
                $tableBuffer[] = $line;
                continue;
            }
            $flushTable();

            $trimmed = trim($line);
            if ($trimmed === '') {
                $closeList();
                continue;
            }

            // 分隔线
            if (preg_match('/^(-{3,}|\*{3,})/', $trimmed)) {
                $closeList();
                $html[] = '<hr>';
                continue;
            }

            // 标题
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $closeList();
                $level = strlen($m[1]);
                $id = 'h-' . substr(md5($m[2]), 0, 8);
                $html[] = "<h{$level} id=\"{$id}\">" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }

            // 引用
            if (str_starts_with($trimmed, '>')) {
                $closeList();
                $html[] = '<blockquote>' . self::inline(ltrim(substr($trimmed, 1))) . '</blockquote>';
                continue;
            }

            // 列表
            if (preg_match('/^([-*+])\s+(.*)$/', $trimmed, $m)) {
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::inline($m[2]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $closeList();
            $html[] = '<p>' . self::inline($trimmed) . '</p>';
        }

        $flushTable();
        $closeList();
        if ($inCode && $codeBuffer !== []) {
            $html[] = '<pre class="md-code"><code>' . View::e(implode("\n", $codeBuffer)) . '</code></pre>';
        }

        return implode("\n", $html);
    }

    private static function cells(string $row): array
    {
        // 按未转义的 | 切分：`\|` 表示单元格内的字面竖线（例如 `string \| int`），
        // 否则会被误当成列分隔符，导致整行错位。
        $row = trim($row);
        $row = trim($row, '|');

        $cells = [];
        $buffer = '';
        $length = strlen($row);
        for ($i = 0; $i < $length; $i++) {
            $char = $row[$i];
            if ($char === '\\' && $i + 1 < $length && $row[$i + 1] === '|') {
                $buffer .= '|';
                $i++;
                continue;
            }
            if ($char === '|') {
                $cells[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $cells[] = trim($buffer);

        return $cells;
    }

    private static function inline(string $text): string
    {
        $escaped = View::e($text);

        // 行内代码优先占位，避免内部被其他规则改写
        $codes = [];
        $escaped = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = $m[1];
            return "\x00CODE" . (count($codes) - 1) . "\x00";
        }, $escaped);

        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $escaped);
        // 站内链接在当前标签打开（不弹新窗口），只有外部绝对地址才新开标签
        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $m): string {
            $href = $m[2];
            $isExternal = (bool) preg_match('#^(https?:)?//#i', $href);
            $attr = $isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . $href . '"' . $attr . '>' . $m[1] . '</a>';
        }, $escaped);

        return preg_replace_callback('/\x00CODE(\d+)\x00/', static function (array $m) use ($codes): string {
            return '<code>' . ($codes[(int) $m[1]] ?? '') . '</code>';
        }, $escaped);
    }
}
