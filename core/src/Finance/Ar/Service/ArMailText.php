<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Shared\Mailbox\MailboxMessage;

/**
 * Mail text, both ways: a message body as the plain text the register stores and the model reads (the source's
 * `strip_html` and `body_to_text`), and what the composer sends (`text_to_html`, `_address_list`,
 * `_decode_attachments`, `strip_subject_line`).
 */
final class ArMailText
{
    public const MAX_ATTACHMENTS = 10;

    /** 3 MiB in total, decoded: Graph rejects a larger inline attachment set. */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    /** The source's address check: no display name, one `@`, a dot in the domain, none of `, ; < >` or a space. */
    private const ADDRESS = '/^[^@\s,;<>]+@[^@\s,;<>]+\.[^@\s,;<>]+$/u';

    private function __construct()
    {
    }

    public static function of(MailboxMessage $message): string
    {
        $body = $message->body;
        if ($body === '') {
            return '';
        }
        if ($message->bodyType === MailboxMessage::BODY_HTML || preg_match('/^\s*<(!doctype|html|head|body|div|p|table|span|br)\b/i', $body) === 1) {
            return self::stripHtml($body);
        }

        return $body;
    }

    /**
     * A plain-text draft as HTML without losing its line breaks. It escapes first (`&`, `<`, `>`): the text is
     * model-written from customer mail and then editable, so it must not inject markup into a message that goes out.
     * Blank-line-separated blocks become paragraphs; a single newline becomes `<br>`.
     */
    public static function toHtml(string $text): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $normalised);
        $html = '';
        foreach (explode("\n\n", $escaped) as $block) {
            $block = trim($block);
            if ($block !== '') {
                $html .= '<p style="margin:0 0 1em 0">' . str_replace("\n", '<br>', $block) . '</p>';
            }
        }

        return $html;
    }

    /** One leading `Subject: ...` line, which a model sometimes writes into a reply body, is removed. */
    public static function stripSubjectLine(string $text): string
    {
        return trim(preg_replace('/\A\s*subject\s*:[^\n]*\n+/i', '', $text, 1) ?? $text);
    }

    /**
     * A list, or a string split on commas, semicolons and whitespace, as addresses: trimmed, de-duplicated without
     * regard to case, and in the case they were typed.
     *
     * @return list<string>
     */
    public static function addressList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $seen = [];
        foreach ($value as $item) {
            $address = trim(is_scalar($item) ? (string) $item : '');
            if ($address === '') {
                continue;
            }
            foreach ($seen as $earlier) {
                if (strcasecmp($earlier, $address) === 0) {
                    continue 2;
                }
            }
            $seen[] = $address;
        }

        return $seen;
    }

    /** True for `name@domain.tld` with no display name. */
    public static function isAddress(string $address): bool
    {
        return preg_match(self::ADDRESS, $address) === 1;
    }

    /**
     * `[{name, content_type, data (base64)}]` as attachments, with the source's refusals.
     *
     * @return list<FinanceMailAttachment>
     * @throws ArInvalid
     */
    public static function attachments(mixed $items): array
    {
        if ($items === null || $items === '' || $items === [] || $items === false) {
            return [];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new ArInvalid('Attachments must be a list');
        }
        if (count($items) > self::MAX_ATTACHMENTS) {
            throw new ArInvalid('Attach at most ' . self::MAX_ATTACHMENTS . ' files');
        }
        $out = [];
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ArInvalid('Each attachment must be an object');
            }
            $rawName = $item['name'] ?? '';
            $name = trim(is_scalar($rawName) ? (string) $rawName : '');
            if ($name === '') {
                throw new ArInvalid('Every attachment needs a file name');
            }
            if (mb_strlen($name) > 255 || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1f]/', $name) === 1) {
                throw new ArInvalid(mb_substr($name, 0, 60) . ' is not a usable file name');
            }
            $data = $item['data'] ?? '';
            $content = is_string($data) ? base64_decode($data, true) : false;
            if ($content === false) {
                throw new ArInvalid($name . ' could not be read');
            }
            if ($content === '') {
                throw new ArInvalid($name . ' is empty');
            }
            $total += strlen($content);
            if ($total > self::MAX_ATTACHMENT_BYTES) {
                throw new ArInvalid('Attachments come to more than ' . intdiv(self::MAX_ATTACHMENT_BYTES, 1024 * 1024) . ' MB in total. Send a link instead, or attach fewer files.');
            }
            $type = $item['content_type'] ?? '';
            $out[] = new FinanceMailAttachment($name, $content, is_string($type) && $type !== '' ? $type : 'application/octet-stream');
        }

        return $out;
    }

    public static function stripHtml(string $html): string
    {
        $text = preg_replace('~<style[^>]*>.*?</style>~is', '', $html) ?? $html;
        $text = preg_replace('~<script[^>]*>.*?</script>~is', '', $text) ?? $text;
        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = preg_replace('~</p>~i', "\n", $text) ?? $text;
        $text = preg_replace('~<[^>]+>~', ' ', $text) ?? $text;
        $text = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], [' ', '&', '<', '>', '"', "'"], $text);
        $text = preg_replace('~[ \t]+~', ' ', $text) ?? $text;
        // Tags collapse to a space, so an inline <b>Sue</b> before a comma leaves "Hi Sue ,": pull the punctuation back.
        $text = preg_replace('~ +([,.;:!?])~', '$1', $text) ?? $text;
        $text = preg_replace('~\n\s*\n~', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
