<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\FourJs;

/**
 * Parses the 4JS P008btch OCR stream. Port of cc/fourjs.php in the legacy site.
 *
 * A record is a tag line (.RER.S009), a field count, then that many value
 * lines. Anything before the first tag (PHP warnings) is skipped. An error is
 * a single "error ..." line and yields no S006 header, so the result is null.
 */
final class FourJsOcrParser
{
    /**
     * @return list<array{tag: string, f: list<string>}>
     */
    public static function records(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count;) {
            if (preg_match('/^\.RER\.([SV]\d+)\s*$/', $lines[$i], $match) !== 1) {
                $i++;
                continue;
            }
            $n = (int) trim($lines[$i + 1] ?? '0');
            $values = array_map(self::text(...), array_slice($lines, $i + 2, max(0, $n)));
            $out[] = ['tag' => $match[1], 'f' => array_values($values)];
            $i += 2 + max(0, $n);
        }

        return $out;
    }

    public static function order(string $raw): ?FourJsDocument
    {
        $recs = self::records($raw);
        $header = self::one($recs, 'S006');
        if ($header === []) {
            return null;
        }
        $orderNo = self::digits($header[0] ?? '');

        return new FourJsDocument(
            type: 'order',
            orderNo: $orderNo,
            invoiceNo: '',
            customerCode: $header[9] ?? '',
            customerName: self::one($recs, 'S008')[1] ?? '',
            contact: self::one($recs, 'S012')[1] ?? '',
            customerPo: $header[10] ?? '',
            currency: $header[11] ?? '',
            gstRate: null,
            total: self::num($header[12] ?? '0'),
            lines: self::lines($recs, $orderNo, ''),
        );
    }

    public static function invoice(string $raw): ?FourJsDocument
    {
        $recs = self::records($raw);
        $header = self::one($recs, 'S006');
        if ($header === []) {
            return null;
        }
        $invoiceNo = self::digits($header[0] ?? '');
        $orderNo = strlen($invoiceNo) > 2 ? substr($invoiceNo, 0, -2) : $invoiceNo;
        $tax = self::one($recs, 'S019');
        $contact = self::one($recs, 'S020')[1] ?? '';
        if ($contact === '') {
            $contact = self::one($recs, 'S012')[4] ?? '';
        }

        return new FourJsDocument(
            type: 'invoice',
            orderNo: $orderNo,
            invoiceNo: $invoiceNo,
            customerCode: $header[9] ?? '',
            customerName: self::one($recs, 'S008')[1] ?? '',
            contact: $contact,
            customerPo: $header[10] ?? '',
            currency: $header[11] ?? '',
            gstRate: isset($tax[5]) ? self::num($tax[5]) : null,
            total: self::num($header[12] ?? '0'),
            lines: self::lines($recs, $orderNo, $invoiceNo),
        );
    }

    /** "      64.00", " 2,560.00" and "-89332.08" are all numbers. Returned as a plain decimal string. */
    public static function num(string $raw): string
    {
        $clean = str_replace([',', ' '], '', trim($raw));
        if ($clean === '' || preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return '0';
        }

        return $clean;
    }

    /** Digits only. */
    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * @param list<array{tag: string, f: list<string>}> $recs
     * @return list<string>
     */
    private static function one(array $recs, string $tag): array
    {
        foreach ($recs as $record) {
            if ($record['tag'] === $tag) {
                return $record['f'];
            }
        }

        return [];
    }

    /**
     * @param list<array{tag: string, f: list<string>}> $recs
     * @return list<FourJsLine>
     */
    private static function lines(array $recs, string $orderNo, string $invoiceNo): array
    {
        $clean = [];
        $note = [];
        foreach ($recs as $record) {
            $f = $record['f'];
            if ($record['tag'] === 'V4204' && ($f[3] ?? '') !== '') {
                $clean[(int) ($f[1] ?? 0)] = $f[3];
            }
            if ($record['tag'] === 'V4203') {
                $parts = array_filter(
                    array_map(static fn (string $v): string => trim($v, " |"), array_slice($f, 3)),
                    static fn (string $v): bool => $v !== '',
                );
                $note[(int) ($f[1] ?? 0)] = implode(' / ', $parts);
            }
        }
        $lines = [];
        foreach ($recs as $record) {
            if ($record['tag'] !== 'S009') {
                continue;
            }
            $f = $record['f'];
            $no = (int) ($f[1] ?? 0);
            $lines[] = new FourJsLine(
                orderNo: $orderNo,
                invoiceNo: $invoiceNo,
                lineNo: $no,
                productCode: $f[2] ?? '',
                description: $clean[$no] ?? ($f[8] ?? ''),
                qty: self::num($f[4] ?? '0'),
                unit: $f[5] ?? '',
                packs: (int) self::num($f[10] ?? '0'),
                packSize: self::num($f[11] ?? '0'),
                unitPrice: self::num($f[6] ?? '0'),
                net: self::num($f[7] ?? '0'),
                warehouse: $f[12] ?? '',
                comment: $note[$no] ?? '',
            );
        }

        return $lines;
    }

    private static function text(string $value): string
    {
        $value = trim($value);

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
