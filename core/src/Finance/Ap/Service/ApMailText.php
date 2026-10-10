<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;

/**
 * What the composer sends, as text: the recipient and cc rules, the body as HTML and the user's attachments (the source's
 * `_address_list`, `_plain_text_to_html` and `_decode_attachments`, `app.py` 2098-2160).
 */
final class ApMailText
{
    public const MAX_ATTACHMENTS = 10;

    /** 3 MiB in total, decoded; the invoice document that goes with vendor mail counts towards it (fault R9). */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    public const TOO_BIG = 'Attachments come to more than 3 MB in total. Send a link instead, or attach fewer files.';

    /** The source's address check: no display name, one `@`, a dot in the domain, none of `, ; < >` or a space. */
    private const ADDRESS = '/^[^@\s,;<>]+@[^@\s,;<>]+\.[^@\s,;<>]+$/u';

    private function __construct()
    {
    }

    /**
     * `_plain_text_to_html`: the text escaped (`& < > " '`) inside one `white-space: pre-wrap` div, so its line breaks show. The
     * text is typed or model-written, so it must not inject markup into a message that goes out.
     */
    public static function toHtml(string $text): string
    {
        $escaped = str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], $text);

        return '<div style="font-family:Arial,sans-serif;white-space:pre-wrap">' . $escaped . '</div>';
    }

    /** True for `name@domain.tld` with no display name. */
    public static function isAddress(string $address): bool
    {
        return preg_match(self::ADDRESS, $address) === 1;
    }

    /**
     * The cc list: a list, or a string split on runs of commas, semicolons and whitespace. Each address is trimmed and lower-cased;
     * empty ones, one equal to `$to` and repeats are dropped, in order.
     *
     * @return list<string>
     */
    public static function ccList(mixed $value, string $to): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $address = strtolower(trim(is_scalar($item) ? (string) $item : ''));
            if ($address === '' || $address === $to || in_array($address, $out, true)) {
                continue;
            }
            $out[] = $address;
        }

        return $out;
    }

    /**
     * `[{name, content_type, data (base64)}]` as attachments, with the source's refusals (all of them a 422 here).
     *
     * @return list<FinanceMailAttachment>
     * @throws ApInvalid
     */
    public static function attachments(mixed $items): array
    {
        if ($items === null || $items === '' || $items === [] || $items === false) {
            return [];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new ApInvalid('Attachments must be a list');
        }
        if (count($items) > self::MAX_ATTACHMENTS) {
            throw new ApInvalid('Attach at most ' . self::MAX_ATTACHMENTS . ' files');
        }
        $out = [];
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ApInvalid('Each attachment must be an object');
            }
            $rawName = $item['name'] ?? '';
            $name = trim(is_scalar($rawName) ? (string) $rawName : '');
            if ($name === '') {
                throw new ApInvalid('Every attachment needs a file name');
            }
            if (mb_strlen($name) > 255 || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1f]/', $name) === 1) {
                throw new ApInvalid(mb_substr($name, 0, 60) . ' is not a usable file name');
            }
            $data = $item['data'] ?? '';
            // Strict as Python's `validate=True`: any character outside the alphabet, a newline included, and wrong padding fail
            // (PHP's own strict mode skips whitespace).
            $content = is_string($data) && self::isBase64($data) ? base64_decode($data, true) : false;
            if ($content === false) {
                throw new ApInvalid($name . ' could not be read');
            }
            if ($content === '') {
                throw new ApInvalid($name . ' is empty');
            }
            $total += strlen($content);
            if ($total > self::MAX_ATTACHMENT_BYTES) {
                throw new ApInvalid(self::TOO_BIG);
            }
            $type = $item['content_type'] ?? '';
            $out[] = new FinanceMailAttachment($name, $content, is_string($type) && $type !== '' ? $type : 'application/octet-stream');
        }

        return $out;
    }

    /** Standard base64 with correct padding and nothing else (a regex would overflow PCRE's limits on a few megabytes). */
    private static function isBase64(string $data): bool
    {
        $length = strlen($data);
        if ($length % 4 !== 0) {
            return false;
        }
        $body = rtrim($data, '=');
        $padding = $length - strlen($body);

        return $padding <= 2 && strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') === strlen($body);
    }

    /** The source's truthiness for `attach_invoice`, with the strings a form sends for "no" read as no. */
    public static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }
}
