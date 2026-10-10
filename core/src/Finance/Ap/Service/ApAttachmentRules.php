<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;

/**
 * Which attachments of a scanned message are worth reading, and under what name (`_attachment_filename`, `A:1688-1714`).
 *
 * - The type is picked by the name's suffix (`.pdf .png .jpg .jpeg .tif .tiff`, case-insensitive). A name without one of
 *   those takes the suffix of its declared content type (`application/pdf` to `.pdf`, `image/png`, `image/jpeg` and
 *   `image/jpg` to `.jpg`, `image/tiff` to `.tiff`) APPENDED to the name (`Invoice 12345` becomes `Invoice 12345.pdf`,
 *   `report.docx` with a PDF type becomes `report.docx.pdf`); no match is skipped. GIF, WEBP and Office files are never read.
 * - Anything that is not a PDF is skipped when it is inline (signature and logo images) or smaller than the floor
 *   (`FINANCE_AP_MIN_ATTACHMENT_BYTES`, 25,600). PDFs are exempt from both: Apple Mail marks real PDFs inline.
 * - The name is what the invoice, the file row and the tombstone are keyed on, so it is made safe once, here: control
 *   characters become spaces and a name longer than the 255-character column is cut in its stem, never its suffix.
 */
final class ApAttachmentRules
{
    public const MIN_BYTES = 25600;
    public const NAME_WIDTH = 255;
    public const DEFAULT_NAME = 'attachment';

    private const ALLOWED_SUFFIXES = ['.pdf', '.png', '.jpg', '.jpeg', '.tif', '.tiff'];

    private const SUFFIX_BY_TYPE = [
        'application/pdf' => '.pdf',
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
        'image/jpg' => '.jpg',
        'image/tiff' => '.tiff',
    ];

    private function __construct()
    {
    }

    /**
     * The name to read the attachment under, or null to skip it.
     */
    public static function nameFor(MailboxAttachmentSummary $attachment, int $minBytes = self::MIN_BYTES): ?string
    {
        $name = $attachment->name !== '' ? $attachment->name : self::DEFAULT_NAME;
        $suffix = ApDownloadName::suffix($name);
        if (!in_array($suffix, self::ALLOWED_SUFFIXES, true)) {
            $type = strtolower(trim(explode(';', $attachment->contentType)[0]));
            $suffix = self::SUFFIX_BY_TYPE[$type] ?? '';
            if ($suffix === '') {
                return null;
            }
            $name .= $suffix;
        }
        if ($suffix !== '.pdf' && ($attachment->isInline || $attachment->size < $minBytes)) {
            return null;
        }

        return self::safe($name, $suffix);
    }

    /** The content type a model and the file store are told: from the name's suffix (always one of the six). */
    public static function contentTypeOf(string $name): string
    {
        return ApAttachmentService::UPLOAD_TYPES[ApDownloadName::suffix($name)] ?? 'application/octet-stream';
    }

    private static function safe(string $name, string $suffix): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $name) ?? $name;
        if (mb_strlen($name) > self::NAME_WIDTH) {
            $keep = max(0, self::NAME_WIDTH - mb_strlen($suffix));
            $name = mb_substr($name, 0, $keep) . $suffix;
        }

        return $name;
    }
}
