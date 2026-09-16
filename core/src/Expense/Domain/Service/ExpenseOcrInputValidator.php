<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use TriCab\Core\Expense\Module;

/**
 * Guards what may be handed to a paid extraction service: known receipt media,
 * bounded size, and — for PDFs — a bounded page count.
 */
final class ExpenseOcrInputValidator
{
    /**
     * @return array{ok: bool, mimeType: ?string, pages: ?int, reason: ?string}
     */
    public static function inspect(string $bytes, ?string $fileName): array
    {
        $mimeType = self::mimeTypeFor($fileName);
        if ($mimeType === null) {
            return self::result(false, null, null, 'unsupported_file_type');
        }

        $size = strlen($bytes);
        if ($size === 0) {
            return self::result(false, $mimeType, null, 'empty_file');
        }
        if ($size > Module::OCR_MAX_BYTES) {
            return self::result(false, $mimeType, null, 'file_too_large');
        }

        if ($mimeType !== 'application/pdf') {
            return self::result(true, $mimeType, null, null);
        }

        $pages = self::countPdfPages($bytes);
        if ($pages === null) {
            return self::result(false, $mimeType, null, 'unreadable_pdf');
        }
        if ($pages > Module::OCR_MAX_PDF_PAGES) {
            return self::result(false, $mimeType, $pages, 'pdf_too_many_pages');
        }

        return self::result(true, $mimeType, $pages, null);
    }

    public static function mimeTypeFor(?string $fileName): ?string
    {
        if ($fileName === null || trim($fileName) === '') {
            return null;
        }
        $extension = strtolower((string) pathinfo(trim($fileName), PATHINFO_EXTENSION));

        return Module::OCR_ALLOWED_MIME_TYPES[$extension] ?? null;
    }

    /**
     * Counts page objects straight out of the PDF body. Good enough to reject an
     * uploaded statement without pulling in a PDF parser; returns null when the
     * bytes are not a PDF at all.
     */
    public static function countPdfPages(string $bytes): ?int
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            return null;
        }

        $matches = preg_match_all('/\/Type\s*\/Page[^s]/', $bytes);
        if ($matches === false) {
            return null;
        }
        if ($matches > 0) {
            return $matches;
        }

        // Linearised or object-stream PDFs may only state the tree count.
        if (preg_match('/\/Count\s+(\d+)/', $bytes, $count) === 1) {
            return max(1, (int) $count[1]);
        }

        return 1;
    }

    /**
     * @return array{ok: bool, mimeType: ?string, pages: ?int, reason: ?string}
     */
    private static function result(bool $ok, ?string $mimeType, ?int $pages, ?string $reason): array
    {
        return ['ok' => $ok, 'mimeType' => $mimeType, 'pages' => $pages, 'reason' => $reason];
    }
}
