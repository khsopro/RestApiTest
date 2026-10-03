<?php
declare(strict_types=1);

/**
 * Sehr kleiner, sicherer Markdown-Renderer.
 * Der Text wird zuerst komplett maskiert, danach werden nur bekannte Muster umgesetzt.
 *
 *   ## Überschrift      ### Unterüberschrift
 *   **fett**  *kursiv*  [Linktext](https://…)
 *   - Aufzählung        1. Nummerierung
 *   > Zitat / Hervorhebung
 */
function md(?string $text): string
{
    $text = str_replace("\r\n", "\n", (string)$text);
    $blocks = preg_split('/\n\s*\n/', trim($text)) ?: [];
    $out = '';
    foreach ($blocks as $block) {
        $lines = explode("\n", $block);
        if (preg_match('/^(#{2,4})\s+(.*)$/', $lines[0], $m) && count($lines) === 1) {
            $lvl = strlen($m[1]);
            $out .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>";
        } elseif (md_all_match($lines, '/^\s*[-*]\s+/')) {
            $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . md_inline(preg_replace('/^\s*[-*]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        } elseif (md_all_match($lines, '/^\s*\d+[.)]\s+/')) {
            $out .= '<ol>' . implode('', array_map(fn($l) => '<li>' . md_inline(preg_replace('/^\s*\d+[.)]\s+/', '', $l)) . '</li>', $lines)) . '</ol>';
        } elseif (md_all_match($lines, '/^>\s?/')) {
            $out .= '<blockquote>' . implode('<br>', array_map(fn($l) => md_inline(preg_replace('/^>\s?/', '', $l)), $lines)) . '</blockquote>';
        } else {
            $out .= '<p>' . implode('<br>', array_map('md_inline', $lines)) . '</p>';
        }
    }
    return $out;
}

function md_all_match(array $lines, string $re): bool
{
    foreach ($lines as $l) {
        if (!preg_match($re, $l)) {
            return false;
        }
    }
    return true;
}

function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        // Seitenkürzel auflösen, nur sichere Ziele zulassen (kein javascript: o. ä.)
        $url = resolve_link(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return $m[1];
        }
        $ext = preg_match('~^https?://~i', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . e($url) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $s) ?? $s;
    return $s;
}
