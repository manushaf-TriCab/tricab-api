<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\LineEntry;
use TriCab\Core\Rfq\Application\DTO\LinePricing;
use TriCab\Core\Rfq\Application\DTO\ParsedLine;
use TriCab\Core\Rfq\Application\DTO\QuoteHeader;
use TriCab\Core\Rfq\Application\DTO\ReviewRevision;
use TriCab\Core\Rfq\Domain\ValueObject\LineApproval;
use TriCab\Core\Rfq\Domain\ValueObject\LineDisposition;
use TriCab\Core\Rfq\Domain\ValueObject\LineInputType;
use TriCab\Core\Rfq\Domain\ValueObject\LineMatchStatus;

/**
 * A draft's current header and lines, read as `rfq.revision.v1`.
 *
 * The store holds the source's rows as they are (§6.5): each is the parser's
 * match result plus whatever the editor has written onto it. This is the one
 * place those keys are read as the platform's line fields, the way the
 * source's editor reads them:
 *
 *  - **product** `final_product_code` when the row has one, even blank (a
 *    cleared code is cleared, as readiness reads it), else the parser's
 *    `selected_product_code`;
 *  - **approval** `final_status` = `approved`; the **match status** is the
 *    parser's `status`, unless a person set a different one in `final_status`;
 *  - **quantity** `length.total_length` (the editor's), else
 *    `total_length_m` (the parser's), in metres; an accessory (or a row whose
 *    unit is `U`) is counted in units by `number_of_lengths`; anything else
 *    with no length has no quantity yet, rather than a default "1";
 *  - **exclusion** `disposition` = `excluded` or a directive's exclusion,
 *    with the source's reasons (`quote_readiness.exclusion_reason`).
 *
 * Nothing is inferred that the row does not say. Pricing is projected
 * ({@see pricing()}) but sent only to those who hold `RFQ.PRICING.VIEW`
 * (Q9, decided: withheld). Source row
 * references wait for stored documents (plan 2.5), so `source` is null and
 * the text the customer wrote is `requestedText`.
 */
final class StoredRevision
{
    /**
     * @param array<string, mixed>       $quoteDetails
     * @param list<array<string, mixed>> $rows
     */
    public static function project(int $revision, DateTimeImmutable $savedAt, array $quoteDetails, array $rows): ReviewRevision
    {
        return new ReviewRevision(
            $revision,
            $revision > 1 ? $revision - 1 : null,
            $revision > 1 ? ReviewRevision::CAUSE_SAVED : ReviewRevision::CAUSE_CREATED,
            null,
            $savedAt,
            self::header($quoteDetails),
            array_map(self::line(...), $rows),
        );
    }

    /** @param array<string, mixed> $details */
    public static function header(array $details): QuoteHeader
    {
        return new QuoteHeader(
            self::text($details['customer_code'] ?? null) ?? self::text($details['customer_id'] ?? null),
            self::text($details['contact'] ?? null),
            self::text($details['author'] ?? null),
            self::text($details['salesoffice'] ?? null),
            self::text($details['payterms'] ?? null),
            self::text($details['region'] ?? null),
            self::text($details['currency'] ?? null),
            self::text($details['cusref'] ?? null),
            null,
            self::text($details['customer_id'] ?? null),
            self::text($details['customer_name'] ?? null),
            self::text($details['phone'] ?? null),
            QuoteHeaderEdit::isoValidTill($details['validtill'] ?? null),
            self::text($details['comment'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    public static function line(array $row): ParsedLine
    {
        $source = DraftChangeLog::object($row['source'] ?? null);
        $length = DraftChangeLog::object($row['length'] ?? null);
        $finalStatus = strtolower(self::text($row['final_status'] ?? null) ?? '');

        $metres = self::decimal($length['total_length'] ?? null) ?? self::decimal($length['total_length_m'] ?? null);
        $units = ($row['item_type'] ?? null) === 'accessory'
            || strtoupper(self::text($length['unit'] ?? null) ?? self::text($length['uom'] ?? null) ?? '') === 'U';
        $count = $units ? self::decimal($length['number_of_lengths'] ?? null) : null;
        $each = self::decimal($length['length_each'] ?? null) ?? self::decimal($length['length_each_m'] ?? null);
        $cuts = is_int($length['number_of_lengths'] ?? null) ? $length['number_of_lengths'] : null;
        $cutsStated = ($length['cuts_explicit'] ?? false) === true || ($cuts !== null && $cuts > 1);

        return new ParsedLine(
            lineUid: self::text($row['line_uid'] ?? null) ?? '',
            source: null,
            inputType: LineInputType::fromSource($source['input_type'] ?? null),
            requestedText: self::text($source['original_text'] ?? null) ?? self::text($row['raw_text'] ?? null) ?? '',
            matchStatus: LineMatchStatus::fromStorage($finalStatus !== '' && $finalStatus !== 'approved' ? $finalStatus : ($row['status'] ?? null)),
            productCode: self::text(DraftLineEdits::productCode($row)),
            description: self::text(DraftChangeLog::object($row['fields'] ?? null)['description'] ?? null)
                ?? self::text(DraftChangeLog::object($row['selected_product'] ?? null)['desc2'] ?? null),
            quantity: $metres ?? $count,
            unit: $metres === null && $units ? 'U' : 'm',
            cutLengthMetres: $metres !== null && $cutsStated ? $each : null,
            cuts: $metres !== null && $cutsStated ? $cuts : null,
            comment: self::text($row['line_note'] ?? null),
            approval: $finalStatus === 'approved' ? LineApproval::approved() : LineApproval::unreviewed(),
            disposition: self::exclusionReason($row) !== null ? LineDisposition::excluded() : LineDisposition::included(),
            exclusionReason: self::exclusionReason($row),
            warningCodes: [],
            inferredFields: self::strings($row['inferred_fields'] ?? null),
            warnings: self::strings($row['warnings'] ?? null),
            entry: self::entry($row),
            pricing: self::pricing($row),
            confidence: is_numeric($row['confidence'] ?? null) && is_finite((float) $row['confidence']) ? max(0.0, min(1.0, round((float) $row['confidence'], 2))) : null,
            status: $finalStatus !== '' ? $finalStatus : self::text($row['status'] ?? null),
            correctionReason: PythonText::str($row['correction_reason'] ?? ''),
            reviewerNote: PythonText::str($row['reviewer_note'] ?? ''),
            normalizedText: self::text($source['normalized_text'] ?? null),
        );
    }

    /**
     * A row's price as the detailed view shows it: cost from the Cost API,
     * then margin, sell price and total worked out by the parser (parity plan
     * E5). A row nobody has priced yet reads `pending`.
     *
     * @param array<string, mixed> $row
     */
    public static function pricing(array $row): LinePricing
    {
        $pricing = DraftChangeLog::object($row['pricing'] ?? null);
        $margin = $row['margin_percent'] ?? null;

        return new LinePricing(
            self::decimal($row['unit_price'] ?? null),
            self::text($pricing['cost_status'] ?? null),
            strtolower(self::text($pricing['status'] ?? null) ?? 'pending'),
            self::text($pricing['error'] ?? null),
            is_numeric($margin) ? (int) floor((float) $margin) : null,
            DraftChangeLog::truthy($row['margin_locked'] ?? null),
            self::decimal($row['final_unit_price'] ?? null),
            DraftChangeLog::truthy($row['sell_price_locked'] ?? null),
            self::decimal($row['line_total'] ?? null),
            self::text($pricing['quote_currency'] ?? null),
        );
    }

    /**
     * The grid's view of a row: the source editor's `ensureLengthDefaults`
     * (with `repairDoubleMultipliedCuts`), `sourceTrace`, `candidateList`,
     * `tableQuantityHint` and the accessory pack fields.
     *
     * @param array<string, mixed> $row
     */
    public static function entry(array $row): LineEntry
    {
        [$cuts, $each] = self::lengthAsEntered(DraftChangeLog::object($row['length'] ?? null));
        $source = DraftChangeLog::object($row['source'] ?? null);
        $hint = DraftChangeLog::object($row['table_quantity'] ?? null);
        $hintValue = self::text($hint['value'] ?? null);
        $pack = is_numeric($row['units_per_pack'] ?? null) ? (int) floor((float) $row['units_per_pack']) : 1;

        $alternatives = [];
        $candidates = is_array($row['candidates'] ?? null) ? $row['candidates'] : [];
        if (count($candidates) > 1) {
            foreach (array_slice($candidates, 0, 5) as $candidate) {
                $code = is_array($candidate) ? self::text($candidate['product_code'] ?? null) : null;
                if ($code !== null) {
                    $alternatives[] = $code;
                }
            }
        }

        return new LineEntry(
            cuts: $cuts,
            lengthEach: $each,
            accessory: strtolower(self::text($row['line_kind'] ?? null) ?? '') === 'accessory',
            packSize: max(1, $pack),
            packLabel: strtolower(self::text($row['pack_label'] ?? null) ?? 'unit'),
            quantityHint: $hintValue === null ? null : [
                'header' => self::text($hint['header'] ?? null) ?? 'table',
                'value' => $hintValue,
                'unit' => self::text($hint['unit'] ?? null),
            ],
            alternatives: $alternatives,
            trace: [
                'fileName' => self::text($source['file_name'] ?? null),
                'sheet' => self::text($source['sheet'] ?? null),
                'rowNumber' => self::text($source['row_number'] ?? null),
                'entryIndex' => is_int($source['entry_index'] ?? null) ? $source['entry_index'] : 1,
                'splitCount' => is_int($source['split_count'] ?? null) ? $source['split_count'] : 1,
            ],
            directiveExclusion: DraftChangeLog::truthy($row['directive_excluded'] ?? null)
                ? (self::text($row['directive_exclusion_reason'] ?? null) ?? 'by an instruction in the email')
                : null,
            directiveNotes: self::strings($row['directive_notes'] ?? null),
            manualEntry: DraftChangeLog::truthy($row['manual_entry'] ?? null) || ($source['input_type'] ?? null) === 'manual_entry',
        );
    }

    /**
     * Cuts and the length of each, as the editor shows them: the editable
     * `length_each`, else the parser's `length_each_m`; cuts default to 1. A
     * stale row whose `length_each` holds the already-multiplied total is
     * read from the parser's consistent pair instead.
     *
     * @param array<string, mixed> $length
     *
     * @return array{string, ?string}
     */
    public static function lengthAsEntered(array $length): array
    {
        $cuts = self::decimal($length['number_of_lengths'] ?? null);
        $cuts = $cuts === null || (float) $cuts <= 0 ? '1' : $cuts;
        $each = self::decimal($length['length_each'] ?? null) ?? self::decimal($length['length_each_m'] ?? null);

        $canonicalEach = self::decimal($length['length_each_m'] ?? null);
        $canonicalTotal = self::decimal($length['total_length_m'] ?? null);
        if ((float) $cuts > 1 && $canonicalEach !== null && $canonicalTotal !== null && $each !== null
            && abs((float) $canonicalEach * (float) $cuts - (float) $canonicalTotal) < 0.000005
            && abs((float) $each - (float) $canonicalTotal) < 0.000005) {
            $each = $canonicalEach;
        }

        return [$cuts, $each];
    }

    /**
     * The explicit exclusions of `quote_readiness.exclusion_reason`. Its third
     * case, a heading with no product, is a readiness judgement and not a
     * decision anyone made, so it stays with readiness.
     *
     * @param array<string, mixed> $row
     */
    private static function exclusionReason(array $row): ?string
    {
        if (DraftChangeLog::truthy($row['directive_excluded'] ?? null)) {
            $reason = self::text($row['directive_exclusion_reason'] ?? null);

            return 'excluded by a directive' . ($reason !== null ? ': ' . $reason : '');
        }
        if (($row['disposition'] ?? null) === 'excluded') {
            return self::text($row['exclusion_reason'] ?? null) ?? 'Intentionally excluded from this quote';
        }

        return null;
    }

    /** A number as a decimal string, as the parser wrote it: `120.0` is `120`, `55.5` stays. */
    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $text = PythonJson::floatRepr($value);

            return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value);
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = PythonText::strip((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): ?string => self::text($v), $value), static fn (?string $v): bool => $v !== null));
    }
}
