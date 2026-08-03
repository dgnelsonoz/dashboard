<?php
declare(strict_types=1);

function dashboard_markdown_to_html(string $markdown): string {
    $lines = preg_split('/\R/', $markdown);
    $html = '';
    $paragraph = [];
    $blockquote = [];
    $listType = null;
    $inCode = false;
    $code = [];

    $inline = function (string $text): string {
        $formatText = function (string $text): string {
            $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;
            $html = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $html) ?? $html;

            return $html;
        };

        $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';

        foreach ($parts === false ? [$text] : $parts as $part) {
            if (strlen($part) >= 2 && $part[0] === '`' && substr($part, -1) === '`') {
                $html .= '<code>' . htmlspecialchars(substr($part, 1, -1), ENT_QUOTES, 'UTF-8') . '</code>';
                continue;
            }

            $linkParts = preg_split('/(\[[^\]]+\]\([^)]+\))/', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($linkParts === false ? [$part] : $linkParts as $linkPart) {
                if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $linkPart, $matches)) {
                    $href = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                    $label = $formatText($matches[1]);
                    $html .= '<a href="' . $href . '">' . $label . '</a>';
                } else {
                    $html .= $formatText($linkPart);
                }
            }
        }

        return $html;
    };

    $flushParagraph = function () use (&$html, &$paragraph, $inline): void {
        if ($paragraph === []) {
            return;
        }

        $html .= '<p>' . $inline(implode(' ', $paragraph)) . "</p>\n";
        $paragraph = [];
    };

    $flushBlockquote = function () use (&$html, &$blockquote, $inline): void {
        if ($blockquote === []) {
            return;
        }

        $html .= '<blockquote><p>' . $inline(implode(' ', $blockquote)) . "</p></blockquote>\n";
        $blockquote = [];
    };

    $closeList = function () use (&$html, &$listType): void {
        if ($listType === null) {
            return;
        }

        $html .= "</{$listType}>\n";
        $listType = null;
    };

    $openList = function (string $type) use (&$html, &$listType, $closeList): void {
        if ($listType === $type) {
            return;
        }

        $closeList();
        $html .= "<{$type}>\n";
        $listType = $type;
    };

    foreach ($lines === false ? [] : $lines as $line) {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
                $code = [];
                $inCode = false;
            } else {
                $flushParagraph();
                $flushBlockquote();
                $closeList();
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $code[] = $line;
            continue;
        }

        if ($trimmed === '') {
            $flushParagraph();
            $flushBlockquote();
            $closeList();
            continue;
        }

        if (preg_match('/^-{3,}$/', $trimmed)) {
            $flushParagraph();
            $flushBlockquote();
            $closeList();
            $html .= "<hr>\n";
            continue;
        }

        if (preg_match('/^(#{1,3})\s*(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $flushBlockquote();
            $closeList();
            $level = strlen($matches[1]);
            $html .= "<h{$level}>" . $inline(trim($matches[2])) . "</h{$level}>\n";
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $blockquote[] = trim($matches[1]);
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $flushBlockquote();
            $openList('ul');
            $html .= '<li>' . $inline(trim($matches[1])) . "</li>\n";
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $flushBlockquote();
            $openList('ol');
            $html .= '<li>' . $inline(trim($matches[1])) . "</li>\n";
            continue;
        }

        $paragraph[] = $trimmed;
    }

    if ($inCode) {
        $html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
    }

    $flushParagraph();
    $flushBlockquote();
    $closeList();

    return $html;
}
