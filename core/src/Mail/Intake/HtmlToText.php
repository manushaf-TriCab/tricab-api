<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** Plain text from an email body for the classifier. Not a sanitiser: its output is never shown or stored. */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<!--.*?-->#s', ' ', $text) ?? $text;
        $text = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6]|/table)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return self::tidy($text);
    }

    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        // Control characters other than newline have no place in a prompt.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        return trim($text);
    }
}
