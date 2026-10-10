<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * The filing name of a downloaded invoice document (`_download_filename`, T6.5 F3): "vendor - invoice date - total -
 * cost centre", year first so a folder of them sorts by date.
 *
 * - Parts, in order: the matched Odoo vendor name, else the vendor, else `Unknown vendor`; the invoice date as
 *   `yyyy-MM-dd`, else `no date`; the total with two decimals and no thousands separator (`162.10`), else `no total`; the
 *   cost centre only when there is one.
 * - Each part has every run of `\ / : * ? " < > |` and control characters made one space, whitespace collapsed, and
 *   leading and trailing dots and spaces stripped; a part left empty is dropped.
 * - The parts are joined with ` - ` and cut to 180 characters, and then the suffix is added.
 */
final class ApDownloadName
{
    public const MAX_LENGTH = 180;

    public static function forInvoice(ApInvoice $invoice, string $suffix): string
    {
        $vendor = $invoice->odooVendorName !== '' ? $invoice->odooVendorName : ($invoice->vendor !== '' ? $invoice->vendor : 'Unknown vendor');
        $parts = [
            $vendor,
            $invoice->invoiceDate ?? 'no date',
            $invoice->totalAmount === null ? 'no total' : sprintf('%.2f', $invoice->totalAmount),
        ];
        if ($invoice->costCentre !== '') {
            $parts[] = $invoice->costCentre;
        }
        $cleaned = [];
        foreach ($parts as $part) {
            $clean = trim(self::collapse(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $part) ?? $part), '. ');
            if ($clean !== '') {
                $cleaned[] = $clean;
            }
        }

        return mb_substr(implode(' - ', $cleaned), 0, self::MAX_LENGTH) . $suffix;
    }

    /**
     * The extension of a file name, lower-case with its dot, as Python's `Path(name).suffix.lower()`: empty when the name
     * has none (`archive`, `.hidden`, `trailing.`).
     */
    public static function suffix(string $fileName): string
    {
        $parts = preg_split('~[\\\\/]~', $fileName) ?: [];
        $base = (string) end($parts);
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($base) - 1) {
            return '';
        }

        return strtolower(substr($base, $dot));
    }

    /** Python's `" ".join(text.split())`: any run of white space, Unicode included, becomes one space. */
    private static function collapse(string $text): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? $text);
    }
}
