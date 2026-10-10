<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * The invented invoice documents behind `seed-finance-ap-sample-local.sql` (T6.12, the Tri-AR twin is `ArSampleFiles`):
 * twenty-two files, each one the document of the sample invoice with the same number. Twenty are one-page PDFs and two
 * are PNG "photos" of an invoice. Everything in them is made up. The SQL seed names each file's id, blob path, size and
 * SHA-256, and a test pins that those match what this class makes, so the two cannot drift.
 *
 * Locally the blob store is in memory and forgets between requests, so with `FINANCE_AP_SAMPLE_FILES` on (development
 * only, memory blobs only: `Settings` refuses it anywhere else) the container puts these into the store as it builds it.
 * They are never written to a shared store. No random or dated content: the same bytes every time.
 */
final class ApSampleFiles
{
    /**
     * The sample invoice's number (which is also the file's number), then what the document says. `kind` is `pdf` or `png`;
     * a PNG is a drawing of an invoice with no text. A document with `lines` set is a poor scan: those are its lines.
     *
     * @var array<int, array{name: string, kind: string, vendor: string, number: string, currency: string, net: string, tax: string, total: string, extra?: string, lines?: list<string>}>
     */
    private const DOCUMENTS = [
        1 => ['name' => 'AV-20841.pdf', 'kind' => 'pdf', 'vendor' => 'Alder & Vale Logistics Pty Ltd', 'number' => 'AV-20841', 'currency' => 'AUD', 'net' => '1,840.00', 'tax' => '184.00', 'total' => '2,024.00'],
        2 => ['name' => 'QP-7710.pdf', 'kind' => 'pdf', 'vendor' => 'Quillon Packaging Supplies', 'number' => 'QP-7710', 'currency' => 'AUD', 'net' => '5,200.00', 'tax' => '520.00', 'total' => '5,720.00'],
        3 => ['name' => 'QP-7711.pdf', 'kind' => 'pdf', 'vendor' => 'Quillon Packaging Supplies', 'number' => 'QP-7711', 'currency' => 'AUD', 'net' => '980.00', 'tax' => '98.00', 'total' => '1,078.00'],
        4 => ['name' => 'FH-0093.pdf', 'kind' => 'pdf', 'vendor' => 'Fernhill Industrial Supplies Pty Ltd', 'number' => 'FH-0093', 'currency' => 'AUD', 'net' => '4,300.00', 'tax' => '430.00', 'total' => '5,000.00'],
        5 => ['name' => 'MF-5512.pdf', 'kind' => 'pdf', 'vendor' => 'Marlow Freight Services', 'number' => 'MF-5512', 'currency' => 'AUD', 'net' => '12,400.00', 'tax' => '1,240.00', 'total' => '13,640.00'],
        6 => ['name' => 'TC-3071_photo.png', 'kind' => 'png', 'vendor' => 'Tallow Creek Engineering', 'number' => 'TC-3071', 'currency' => 'AUD', 'net' => '8,250.00', 'tax' => '825.00', 'total' => '9,075.00'],
        7 => ['name' => 'BW-1180.pdf', 'kind' => 'pdf', 'vendor' => 'Brightwell Safety Equipment', 'number' => 'BW-1180', 'currency' => 'AUD', 'net' => '640.00', 'tax' => '64.00', 'total' => '704.00'],
        8 => ['name' => 'BW-1180_resent.pdf', 'kind' => 'pdf', 'vendor' => 'Brightwell Safety Equipment', 'number' => 'BW-1180', 'currency' => 'AUD', 'net' => '640.00', 'tax' => '64.00', 'total' => '704.00', 'extra' => 'Resent copy of the same invoice'],
        9 => ['name' => 'KC-9003.pdf', 'kind' => 'pdf', 'vendor' => 'Kiwi Coast Couriers Ltd', 'number' => 'KC-9003', 'currency' => 'NZD', 'net' => '310.00', 'tax' => '46.50', 'total' => '356.50'],
        10 => ['name' => 'SP-2210.pdf', 'kind' => 'pdf', 'vendor' => 'Sable Peak IT Services', 'number' => 'SP-2210', 'currency' => 'USD', 'net' => '2,200.00', 'tax' => '220.00', 'total' => '2,420.00'],
        12 => ['name' => 'MF-5398.pdf', 'kind' => 'pdf', 'vendor' => 'Marlow Freight Services', 'number' => 'MF-5398', 'currency' => 'AUD', 'net' => '6,100.00', 'tax' => '610.00', 'total' => '6,710.00'],
        13 => ['name' => 'Scan_0007.pdf', 'kind' => 'pdf', 'vendor' => 'Unknown Vendor', 'number' => '', 'currency' => '', 'net' => '', 'tax' => '', 'total' => '', 'lines' => ['SCANNED DOCUMENT (sample)', 'The scan is too faint to read.', 'Nothing legible: no vendor, no total.']],
        14 => ['name' => 'TC-2984.pdf', 'kind' => 'pdf', 'vendor' => 'Tallow Creek Engineering', 'number' => 'TC-2984', 'currency' => 'AUD', 'net' => '3,300.00', 'tax' => '330.00', 'total' => '3,630.00'],
        15 => ['name' => 'FH-0071.pdf', 'kind' => 'pdf', 'vendor' => 'Fernhill Industrial Supplies Pty Ltd', 'number' => 'FH-0071', 'currency' => 'AUD', 'net' => '1,500.00', 'tax' => '150.00', 'total' => '1,650.00'],
        16 => ['name' => 'AV-20502.pdf', 'kind' => 'pdf', 'vendor' => 'Alder & Vale Logistics Pty Ltd', 'number' => 'AV-20502', 'currency' => 'AUD', 'net' => '2,750.00', 'tax' => '275.00', 'total' => '3,025.00'],
        18 => ['name' => 'LP-0455_copy.pdf', 'kind' => 'pdf', 'vendor' => 'Larkspur Print and Copy', 'number' => 'LP-0455', 'currency' => 'AUD', 'net' => '420.00', 'tax' => '42.00', 'total' => '462.00', 'extra' => 'Second copy of the same invoice'],
        19 => ['name' => 'IC-114.pdf', 'kind' => 'pdf', 'vendor' => 'Ibis Creek Consulting', 'number' => 'IC-114', 'currency' => 'AUD', 'net' => '7,000.00', 'tax' => '700.00', 'total' => '7,700.00'],
        20 => ['name' => 'WG-6620.pdf', 'kind' => 'pdf', 'vendor' => 'Wombat Gully Electrical', 'number' => 'WG-6620', 'currency' => 'AUD', 'net' => '1,220.00', 'tax' => '122.00', 'total' => '1,342.00'],
        25 => ['name' => 'KC-9011_photo.png', 'kind' => 'png', 'vendor' => 'Kiwi Coast Couriers Ltd', 'number' => 'KC-9011', 'currency' => 'NZD', 'net' => '120.00', 'tax' => '18.00', 'total' => '138.00'],
        27 => ['name' => 'QP-6120.pdf', 'kind' => 'pdf', 'vendor' => 'Quillon Packaging Supplies', 'number' => 'QP-6120', 'currency' => 'GBP', 'net' => '3,000.00', 'tax' => '600.00', 'total' => '3,600.00'],
        28 => ['name' => 'AV-19877.pdf', 'kind' => 'pdf', 'vendor' => 'Alder & Vale Logistics Pty Ltd', 'number' => 'AV-19877', 'currency' => 'AUD', 'net' => '2,100.00', 'tax' => '210.00', 'total' => '2,310.00'],
        31 => ['name' => 'TC-2800.pdf', 'kind' => 'pdf', 'vendor' => 'Tallow Creek Engineering', 'number' => 'TC-2800', 'currency' => 'AUD', 'net' => '2,000.00', 'tax' => '200.00', 'total' => '2,200.00'],
    ];

    private function __construct()
    {
    }

    /**
     * Every sample file keyed by its id: the number of the invoice it belongs to, blob path, name, type, bytes and SHA-256.
     *
     * @return array<string, array{number: int, id: string, blobPath: string, name: string, contentType: string, bytes: string, sha256: string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::DOCUMENTS as $number => $document) {
            $isPng = $document['kind'] === 'png';
            $bytes = $isPng ? self::png($number) : self::pdf(self::lines($document));
            $id = self::fileId($number);
            $out[$id] = [
                'number' => $number,
                'id' => $id,
                'blobPath' => self::blobPath($id),
                'name' => $document['name'],
                'contentType' => $isPng ? 'image/png' : 'application/pdf',
                'bytes' => $bytes,
                'sha256' => hash('sha256', $bytes),
            ];
        }

        return $out;
    }

    /** The `finance.attachments` id of sample invoice number `$number`'s document: 32 hex characters, `a904` then the number. */
    public static function fileId(int $number): string
    {
        return 'a904' . str_pad((string) $number, 28, '0', STR_PAD_LEFT);
    }

    /** A fixed path, not the month-based one a real upload gets, so the seed can name it. */
    public static function blobPath(string $id): string
    {
        return 'ap/sample/' . $id;
    }

    /** Puts every sample file into the store (for a memory store that forgets between requests). */
    public static function load(ProductBlobStore $blobs, string $container): void
    {
        foreach (self::all() as $file) {
            $blobs->putObject($container, $file['blobPath'], $file['contentType'], $file['bytes']);
        }
    }

    /**
     * What the sample document of invoice `$number` says, for a test and the seed's author: vendor, number, currency and the three amounts.
     *
     * @return array{vendor: string, number: string, currency: string, net: string, tax: string, total: string}|null
     */
    public static function facts(int $number): ?array
    {
        $document = self::DOCUMENTS[$number] ?? null;

        return $document === null
            ? null
            : ['vendor' => $document['vendor'], 'number' => $document['number'], 'currency' => $document['currency'], 'net' => $document['net'], 'tax' => $document['tax'], 'total' => $document['total']];
    }

    /**
     * @param array{name: string, kind: string, vendor: string, number: string, currency: string, net: string, tax: string, total: string, extra?: string, lines?: list<string>} $document
     * @return list<string>
     */
    private static function lines(array $document): array
    {
        if (isset($document['lines'])) {
            return $document['lines'];
        }
        $lines = [
            strtoupper($document['vendor']),
            'Tax invoice (sample)',
            'Invoice number: ' . $document['number'],
            'Net     ' . $document['currency'] . ' ' . $document['net'],
            'Tax     ' . $document['currency'] . ' ' . $document['tax'],
            'Total   ' . $document['currency'] . ' ' . $document['total'],
        ];
        if (isset($document['extra'])) {
            $lines[] = $document['extra'];
        }
        $lines[] = 'Invented sample data for TriCab Finance';

        return $lines;
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

    /**
     * A 200 by 130 PNG drawing of an invoice (a blue header, rows of grey "text", a total box): palette colours, with the
     * pixels in uncompressed (stored) deflate blocks so the bytes do not depend on the zlib in use. No text, nothing real.
     * `$seed` varies the lengths of the grey rows, so two drawings are not byte-for-byte the same file.
     */
    public static function png(int $seed = 0): string
    {
        $width = 200;
        $height = 130;
        // 0 white, 1 blue, 2 light grey, 3 mid grey, 4 pale yellow.
        $rows = [];
        for ($y = 0; $y < $height; ++$y) {
            $row = array_fill(0, $width, 0);
            if ($y < 24) {
                $row = array_fill(0, $width, 1);
            } elseif ($y >= 34 && $y < 42) {
                self::fill($row, 12, 90, 3);
            } elseif ($y >= 52 && $y < 108 && ($y - 52) % 14 < 6) {
                self::fill($row, 12, 188, 2);
                self::fill($row, 12, 12 + 30 + ((($y - 52) + $seed) % 5) * 20, 3);
            } elseif ($y >= 112 && $y < 124) {
                self::fill($row, 120, 188, 4);
                self::fill($row, 126, 176, 3);
            }
            $rows[] = "\x00" . pack('C*', ...$row);
        }
        $raw = implode('', $rows);
        $length = strlen($raw);
        $idat = "\x78\x01\x01" . pack('v', $length) . pack('v', $length ^ 0xFFFF) . $raw . hash('adler32', $raw, true);
        $palette = "\xff\xff\xff" . "\x1f\x3a\x6e" . "\xe4\xe7\xec" . "\x8a\x92\x9e" . "\xff\xf3\xc4";

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 3, 0, 0, 0))
            . self::chunk('PLTE', $palette)
            . self::chunk('IDAT', $idat)
            . self::chunk('IEND', '');
    }

    /**
     * @param array<int, int> $row
     */
    private static function fill(array &$row, int $from, int $to, int $colour): void
    {
        for ($x = $from; $x < $to; ++$x) {
            $row[$x] = $colour;
        }
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
