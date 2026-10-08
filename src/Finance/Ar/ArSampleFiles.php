<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * The five invented PDFs behind `seed-finance-ar-sample-local.sql` (decision D52): the remittance advices and two other
 * documents of the reference dataset. Everything in them is made up. The SQL seed names each file's id, blob path, size
 * and SHA-256; a test pins that those match what this class makes, so the two cannot drift.
 *
 * Locally the blob store is in memory and forgets between requests, so with `FINANCE_AR_SAMPLE_FILES` on (development
 * only, memory blobs only: `Settings` refuses it anywhere else) the container puts these into the store as it builds it.
 * They are never written to a shared store.
 */
final class ArSampleFiles
{
    /**
     * The file id (the `finance.attachments` row), then the name and the lines of text on its one page.
     *
     * @var array<string, array{name: string, lines: list<string>}>
     */
    private const FILES = [
        '5a100000000000000000000000000001' => ['name' => 'Remittance_4471.pdf', 'lines' => ['NORTHGATE ELECTRICAL PTY LTD', 'Remittance advice (sample)', 'Payment 4471   07/10/2026', 'INV-88101   6,140.00', 'INV-88130   7,880.50', 'INV-88164   4,400.00', 'Total AUD 18,420.50']],
        '5a100000000000000000000000000002' => ['name' => 'EFT_remittance.pdf', 'lines' => ['KESTREL MINING SERVICES', 'EFT remittance (sample)', 'INV-87950   2,310.00', 'INV-87990   9,450.00', 'INV-88012   1,125.40', 'INV-88077   3,300.00', 'Total paid AUD 16,000.00']],
        '5a100000000000000000000000000003' => ['name' => 'Remittance_4398.pdf', 'lines' => ['NORTHGATE ELECTRICAL PTY LTD', 'Remittance advice (sample)', 'INV-87611   3,456.00', 'INV-87640   3,456.00', 'Total AUD 6,912.00']],
        '5a100000000000000000000000000004' => ['name' => 'Credit_application_Bluewater.pdf', 'lines' => ['BLUEWATER MARINE ELECTRICAL', 'Trade account application (sample)', 'Requested limit: 25,000']],
        '5a100000000000000000000000000005' => ['name' => 'Damaged_drum_photo_report.pdf', 'lines' => ['CALDER & FINCH BUILDERS', 'Damage report (sample)', 'One drum damaged in transit']],
    ];

    private function __construct()
    {
    }

    /**
     * Every sample file: its id, blob path, name, type, bytes and SHA-256.
     *
     * @return array<string, array{id: string, blobPath: string, name: string, contentType: string, bytes: string, sha256: string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::FILES as $id => $file) {
            $bytes = self::pdf($file['lines']);
            $out[$id] = [
                'id' => $id,
                'blobPath' => self::blobPath($id),
                'name' => $file['name'],
                'contentType' => 'application/pdf',
                'bytes' => $bytes,
                'sha256' => hash('sha256', $bytes),
            ];
        }

        return $out;
    }

    /** A fixed path, not the month-based one a real upload gets, so the seed can name it. */
    public static function blobPath(string $id): string
    {
        return 'ar/sample/' . $id;
    }

    /** Puts every sample file into the store (for a memory store that forgets between requests). */
    public static function load(ProductBlobStore $blobs, string $container): void
    {
        foreach (self::all() as $file) {
            $blobs->putObject($container, $file['blobPath'], $file['contentType'], $file['bytes']);
        }
    }

    /**
     * A one-page PDF with a few lines of text, built by hand so no package is needed.
     *
     * @param list<string> $lines
     */
    public static function pdf(array $lines): string
    {
        $text = 'BT /F1 12 Tf 60 780 Td 16 TL';
        foreach ($lines as $line) {
            $text .= ' (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ') Tj T*';
        }
        $text .= ' ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
