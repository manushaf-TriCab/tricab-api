<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;

/**
 * `mailing.py`'s helpers, ported exactly: a plain-text draft as HTML, the address list, and the
 * attachments from the request. The limits are the browser's own (`lib/attachments.js`) and the
 * ledger's (`FinanceMailRules`).
 */
final class TaskMailText
{
    public const MAX_ATTACHMENTS = 10;

    /** 3 MiB in total, decoded: Graph rejects a larger inline attachment set. */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    /**
     * Renders a draft as HTML without losing its line breaks. It escapes first: the text is
     * model-written and then editable, so it must not be able to inject markup into an outbound
     * message. Blank-line-separated blocks become paragraphs; a single newline becomes `<br>`.
     * An http(s) address becomes a link to itself (13 Oct 2026: the reminder's links to the task
     * and the task list), without a full stop or bracket that ends the sentence.
     */
    public static function toHtml(string $text): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        // Python's html.escape(quote=False): & < > only.
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $normalised);
        $html = '';
        foreach (explode("\n\n", $escaped) as $block) {
            $block = TaskRules::strip($block);
            if ($block === '') {
                continue;
            }
            $block = (string) preg_replace_callback(
                '~https?://[^\s<>"]+~',
                static function (array $m): string {
                    $url = rtrim($m[0], '.,;:!?)');

                    return '<a href="' . $url . '">' . $url . '</a>' . substr($m[0], strlen($url));
                },
                $block,
            );
            $html .= '<p style="margin:0 0 1em 0">' . str_replace("\n", '<br>', $block) . '</p>';
        }

        return $html;
    }

    /**
     * A string ("a@x.com, b@y.com", with `;` as a separator too) or a list, as lower-case addresses.
     *
     * @return list<string>
     */
    public static function addressList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === [] || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', str_replace(';', ',', $value));
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $address = strtolower(TaskRules::strip(is_scalar($item) ? (string) $item : ''));
            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * `[{name, content_type, data (base64)}]` as attachments.
     *
     * @return list<FinanceMailAttachment>
     * @throws TaskInvalid with the source's message
     */
    public static function attachments(mixed $items): array
    {
        if ($items === null || $items === '' || $items === [] || $items === false) {
            return [];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new TaskInvalid('Attachments must be a list');
        }
        if (count($items) > self::MAX_ATTACHMENTS) {
            throw new TaskInvalid('Attach at most ' . self::MAX_ATTACHMENTS . ' files');
        }
        $out = [];
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new TaskInvalid('Each attachment must be an object');
            }
            $rawName = $item['name'] ?? '';
            $name = TaskRules::strip(is_scalar($rawName) ? (string) $rawName : '');
            if ($name === '') {
                throw new TaskInvalid('Every attachment needs a file name');
            }
            if (mb_strlen($name) > 255 || str_contains($name, '/') || str_contains($name, '\\')) {
                throw new TaskInvalid(mb_substr($name, 0, 60) . ' is not a usable file name');
            }
            $data = $item['data'] ?? '';
            $content = is_string($data) ? base64_decode($data, true) : false;
            if ($content === false) {
                throw new TaskInvalid($name . ' could not be read');
            }
            if ($content === '') {
                throw new TaskInvalid($name . ' is empty');
            }
            $total += strlen($content);
            if ($total > self::MAX_ATTACHMENT_BYTES) {
                throw new TaskInvalid(
                    'Attachments come to more than ' . intdiv(self::MAX_ATTACHMENT_BYTES, 1024 * 1024)
                    . ' MB in total. Attach fewer or smaller files.',
                );
            }
            $type = $item['content_type'] ?? '';
            $out[] = new FinanceMailAttachment($name, $content, is_string($type) && $type !== '' ? $type : 'application/octet-stream');
        }

        return $out;
    }
}
