<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

/**
 * Untrusted text (an email, a document, a person's instruction) goes to the model between per-call random markers its
 * own text cannot reproduce, as the mail classifier and the task drafter do. `<<<` and `>>>` inside the text are
 * broken up so it cannot close the fence early.
 */
final class Ap2Fence
{
    public static function clean(string $text): string
    {
        return str_replace(['<<<', '>>>'], ['< < <', '> > >'], $text);
    }

    public static function wrap(string $label, string $nonce, string $text): string
    {
        return '<<<' . $label . '_' . $nonce . ">>>\n" . self::clean($text) . "\n<<<END_" . $label . '_' . $nonce . '>>>';
    }

    /** At most `$max` characters (on a UTF-8 boundary), with a note when it was cut. */
    public static function head(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n[... the rest is not shown]";
    }

    /** One line, at most `$max` characters. */
    public static function line(string $text, int $max): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($line) <= $max ? $line : rtrim(mb_substr($line, 0, $max - 1)) . '…';
    }
}
