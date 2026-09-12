<?php

namespace BWG\ComplianceRules;

/**
 * Framework-independent replacements for the two WordPress text helpers
 * the ported rules relied on (`wp_strip_all_tags`, `esc_html`) -- this
 * package has no WordPress dependency, so consuming plugins' adapters
 * stay responsible for any WP-specific escaping/formatting on top of
 * what these do.
 */
final class Text
{
    /**
     * Mirrors wp_strip_all_tags(): drops <script>/<style> blocks entirely
     * (not just their tags), strips remaining tags, then trims.
     */
    public static function stripAllTags(string $text): string
    {
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        $text = strip_tags($text);
        return trim($text);
    }

    /**
     * Mirrors esc_html()'s core behavior for plain-text display: HTML
     * special characters escaped, UTF-8 assumed.
     */
    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Extracts a short, HTML-escaped excerpt of surrounding text around a
     * matched substring, with ellipses when truncated at either end.
     */
    public static function excerptAround(string $text, string $match, int $context = 80): string
    {
        $pos = mb_stripos($text, $match);
        if (false === $pos) {
            return self::escapeHtml(mb_substr($match, 0, 120));
        }

        $start = max(0, $pos - $context);
        $end = min(mb_strlen($text), $pos + mb_strlen($match) + $context);
        $excerpt = mb_substr($text, $start, $end - $start);

        if ($start > 0) {
            $excerpt = "\xE2\x80\xA6" . $excerpt; // …
        }
        if ($end < mb_strlen($text)) {
            $excerpt .= "\xE2\x80\xA6";
        }

        return self::escapeHtml($excerpt);
    }
}
