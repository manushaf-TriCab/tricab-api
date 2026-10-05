<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * A save's line changes, applied to the stored rows on the server.
 *
 * The source's editor sent every row back whole, so the browser could write
 * any key — including the parser's own. Here the browser names lines by
 * `lineUid` and says only what a person decided; every other key of every
 * row is the one already stored. The decisions are the source editor's own
 * (`static/app.js`), with its rules:
 *
 *  - **Approve** — `approveRowAt`: `final_status` becomes `approved` and the
 *    row's open assumptions and warnings join `confirmed_fields`. Refused, as
 *    `canApproveRow` refuses it, for an excluded line or one with no product.
 *    Withdrawing an approval puts back the parser's status. `approved` null
 *    leaves the approval as stored: the editor draws no Approve button
 *    (parity plan D4), as the source's does not.
 *  - **Comment** — `line_note`, the text 4js carries on the line.
 *  - **Product code** (E3.3) — the `change` handler on `final_product_code`:
 *    upper-cased, and choosing a product resolves the line (`final_status`
 *    `matched`). Any code is accepted, as in the source: sales add accessories
 *    the catalogue does not carry, and pricing and 4js say so.
 *  - **Quantity** (E3.4) — the `input` handler on `length.*`: cuts and the
 *    length of each, the total derived from them (`recalculateTotalLength`),
 *    the length locked against re-snapping, MOQ and prepack adjustments
 *    cleared (`clearMakeMoq`) and the cut note rewritten (`applyCutsNote`).
 *    On an accessory the cuts are whole sellable packs.
 *  - **New line** (E3.6) — the source's `blankRow`, under the uid the browser
 *    gave it. A new line with nothing in it is not saved (`quoteRows`).
 *    An added accessory (E7.2) starts from the sealed row the server proposed.
 *  - **Status, correction reason, reviewer note** (E5.4) — the row detail's
 *    `final_status` select, `correction_reason` select and `reviewer_note`
 *    input. Choosing `approved` there is Approve, with its refusals.
 *  - **Delete** — a line left out of the save is removed. The change log
 *    records it (`DraftRowSet`).
 *
 * Lines are saved in the order sent, so an inserted line keeps its place.
 *
 * A code or quantity change leaves the line's pricing `pending`, as the
 * source marks it (`markPricingPending`) before re-asking the Cost API. The
 * old price belongs to the old product or length, so it is never saved as
 * current; readiness then holds the quote until pricing (plan 4.3) runs.
 */
final class DraftLineEdits
{
    /** The row detail's status select (`static/app.js`). */
    public const STATUSES = [
        'matched', 'needs_clarification', 'multiple_candidates', 'accessory',
        'not_cable', 'unresolved', 'approved', 'flagged',
    ];

    /** The row detail's correction reasons; `''` is none. */
    public const CORRECTION_REASONS = [
        '', 'compound_line_split', 'wrong_series', 'wrong_core_count', 'wrong_cross_section',
        'wrong_conductor', 'wrong_earth_preference', 'wrong_screen_or_armour',
        'wrong_length_or_quantity', 'manual_product_code', 'other',
    ];

    /** `reviewer_note`'s cap, as `line_note`'s. */
    public const MAX_REVIEWER_NOTE = 2_000;

    /** `QUANTITY_WARNING_PREFIXES`: warnings a manual quantity makes untrue. */
    private const QUANTITY_WARNING_PREFIXES = [
        'MAKE cable quantity rounded to MOQ multiple:',
        'MAKE cable priced at MOQ:',
        'Prepack cable quoted as whole spools:',
    ];

    /** `QUANTITY_NOTE_PREFIXES`. */
    private const QUANTITY_NOTE_PREFIXES = ['Defaulted to MAKE MOQ', 'Defaulted to prepack length'];

    private const MAKE_MOQ_NOTE_RE = '/^(?:RQ )?\d+(?:\.\d+)?m below MOQ(?:\. Set length \d+(?:\.\d+)?m\.?)?$/';
    private const PREPACK_ROUNDING_NOTE_RE = '/^(?:\d+(?:\.\d+)?m prepack: |RQ )\d+(?:\.\d+)?m -> \d+(?:\.\d+)?m \(\d+(?:\.\d+)? x \d+(?:\.\d+)?m\)$/';
    private const PREPACK_BREAKDOWN_NOTE_RE = '/^\(\d+(?:\.\d+)? x \d+(?:\.\d+)?m\)$/';
    private const CUTS_NOTE_PREFIX = 'Cuts:';
    private const CUTS_NOTE_RE = '/\(\d+(?:\.\d+)? x \d+(?:\.\d+)?m?\)/';

    /**
     * @param list<array<string, mixed>> $stored the rows as `StoredDraft` holds them
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}> $lines
     *        every line to keep, in order, with its decisions
     * @param string $region the header's region, for a pricing placeholder
     * @param array<string, array<string, mixed>> $accessories the sealed accessory rows by the uid of the new line that
     *        adds each (parity plan E7.2); such a line starts from that row, not from a blank one
     *
     * @return list<array<string, mixed>> the rows to save
     *
     * @throws InvalidRfqValue for an unknown or repeated line, or an approval the line cannot take
     */
    public static function apply(array $stored, array $lines, string $region = 'australia', array $accessories = []): array
    {
        $byUid = [];
        foreach ($stored as $row) {
            $byUid[PythonText::str($row['line_uid'] ?? '')] = $row;
        }

        $seen = [];
        $rows = [];
        foreach ($lines as $line) {
            $uid = $line['lineUid'];
            if (isset($seen[$uid])) {
                throw InvalidRfqValue::required('line', sprintf('line %s appears twice.', $uid));
            }
            $seen[$uid] = true;
            if (($line['isNew'] ?? false) === true) {
                if (isset($byUid[$uid]) || !StoreFormat::isId($uid)) {
                    throw InvalidRfqValue::required('line', sprintf('new line %s needs an unused 32-character id.', $uid));
                }
                $row = self::edit($accessories[$uid] ?? self::blankRow($uid, $region), $line, $region);
                if (!self::isBlank($row)) {
                    $rows[] = $row;
                }
                continue;
            }
            if (!isset($byUid[$uid])) {
                throw InvalidRfqValue::required('line', sprintf('line %s is not on this RFQ; reload it.', $uid));
            }
            $rows[] = self::edit($byUid[$uid], $line, $region);
        }

        return $rows;
    }

    /**
     * The code readiness reads (`quote_readiness.product_code`): a cleared
     * `final_product_code` does not bring back the parser's `selected_product_code`.
     *
     * @param array<string, mixed> $row
     */
    public static function productCode(array $row): string
    {
        $value = array_key_exists('final_product_code', $row) ? $row['final_product_code'] : ($row['selected_product_code'] ?? null);

        return PythonText::strip($value === null ? '' : PythonText::str($value));
    }

    /**
     * @param array<string, mixed> $row
     * @param array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string} $edit
     *
     * @return array<string, mixed>
     */
    private static function edit(array $row, array $edit, string $region): array
    {
        $comment = PythonText::strip($edit['comment']);
        if ($comment !== PythonText::editText($row['line_note'] ?? null)) {
            $row['line_note'] = $comment;
        }

        $code = $edit['productCode'] ?? null;
        if ($code !== null) {
            $typed = PythonText::upper(PythonText::strip($code));
            if ($typed !== self::productCode($row)) {
                $row['final_product_code'] = $typed;
                $row['final_status'] = 'matched';
                $row['pricing'] = self::pricingPlaceholder($typed === '' ? 'missing_code' : 'pending', $region);
            }
        }

        $row = self::quantity($row, $edit['cuts'] ?? null, $edit['lengthEach'] ?? null, $region);
        $row = self::pricingChoices($row, $edit['marginPercent'] ?? null, $edit['sellPrice'] ?? null);

        $before = $row['final_status'] ?? null;
        $row = self::review($row, $edit['status'] ?? null, $edit['correctionReason'] ?? null, $edit['reviewerNote'] ?? null);

        // A status chosen in the row detail is the decision; the Approve flag
        // sent beside it describes the line as it was.
        return ($row['final_status'] ?? null) !== $before ? $row : self::approval($row, $edit['approved']);
    }

    /**
     * The row detail's review fields. A status the line already shows (its
     * `final_status`, else the parser's `status`) changes nothing, so a save
     * that merely repeats it does not take the line off the parser's answer.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function review(array $row, ?string $status, ?string $reason, ?string $note): array
    {
        if ($status !== null) {
            $status = strtolower(trim($status));
            $shown = PythonText::str($row['final_status'] ?? ($row['status'] ?? ''));
            if ($status !== '' && $status !== $shown) {
                if ($status === 'approved') {
                    $row = self::approval($row, true);
                } else {
                    $row['final_status'] = $status;
                }
            }
        }
        if ($reason !== null && trim($reason) !== PythonText::str($row['correction_reason'] ?? '')) {
            $row['correction_reason'] = trim($reason);
        }
        if ($note !== null && PythonText::strip($note) !== PythonText::editText($row['reviewer_note'] ?? null)) {
            $row['reviewer_note'] = PythonText::strip($note);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function quantity(array $row, ?string $cuts, ?string $each, string $region): array
    {
        if ($cuts === null && $each === null) {
            return $row;
        }
        $length = DraftChangeLog::object($row['length'] ?? null);
        [$storedCuts, $storedEach] = StoredRevision::lengthAsEntered($length);
        $cuts = $cuts === null || trim($cuts) === '' ? $storedCuts : trim($cuts);
        $each = $each === null ? $storedEach : (trim($each) === '' ? null : trim($each));
        $accessory = strtolower(PythonText::str($row['line_kind'] ?? '')) === 'accessory';
        if ($accessory) {
            $cuts = (string) max(1, (int) ceil((float) $cuts));
        }
        if (self::same($cuts, $storedCuts) && self::same($each, $storedEach)) {
            return $row;
        }

        if (!$accessory) {
            $row = self::clearMakeMoq($row);
        }
        $cutCount = self::number($cuts);
        $length['number_of_lengths'] = $cutCount;
        $length['length_each'] = $each === null ? '' : self::number($each);
        $length['total_length'] = $each === null ? '' : self::number(self::format((float) $each * (float) $cuts));
        $row['length'] = $length;
        if (!$accessory) {
            $row['length_locked'] = true;
            $row['length_defaulted'] = false;
            $row = self::applyCutsNote($row, (float) $cuts, $each === null ? null : (float) $each);
        }
        if (self::productCode($row) !== '') {
            $status = DraftChangeLog::object($row['pricing'] ?? null)['status'] ?? null;
            if ($status !== 'pending' && $status !== 'loading') {
                $row['pricing'] = self::pricingPlaceholder('pending', $region);
            }
        }

        return $row;
    }

    /**
     * What the rep chose about the price, as the source's editor records it
     * (parity plan E5.3): a typed margin (`margin_locked`) outranks the
     * customer's default and takes the line off a typed sell price; a typed sell
     * price (`sell_price_locked`) holds and the margin is derived from it. Blank
     * hands the line back to the margin it would have had. The price itself is
     * worked out by the parser's pricing pass, never here.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function pricingChoices(array $row, ?string $margin, ?string $sell): array
    {
        if ($margin !== null) {
            $typed = trim($margin);
            if ($typed === '') {
                unset($row['margin_locked']);
            } else {
                $row['margin_percent'] = (int) floor((float) $typed);
                $row['margin_locked'] = true;
                unset($row['sell_price_locked']);
            }
        }
        if ($sell !== null) {
            $typed = trim($sell);
            if ($typed === '') {
                unset($row['sell_price_locked']);
            } else {
                // Three decimals, as 4js holds a price; a price is never zero.
                $row['final_unit_price'] = round((float) $typed, 3);
                $row['sell_price_locked'] = true;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function approval(array $row, ?bool $approve): array
    {
        if ($approve === null) {
            return $row;
        }
        $approved = strtolower(PythonText::str($row['final_status'] ?? '')) === 'approved';
        if ($approve && !$approved) {
            if (self::excluded($row)) {
                throw InvalidRfqValue::required('approval', 'an excluded line cannot be approved.');
            }
            if (self::productCode($row) === '') {
                throw InvalidRfqValue::required('approval', 'choose a product for this line before approving it.');
            }
            $row['confirmed_fields'] = self::confirmed($row);
            $row['final_status'] = 'approved';
        } elseif (!$approve && $approved) {
            unset($row['final_status']);
        }

        return $row;
    }

    /**
     * The source's `blankRow`: a line a person adds, with no source text.
     *
     * @return array<string, mixed>
     */
    private static function blankRow(string $uid, string $region): array
    {
        return [
            'raw_text' => '',
            'source' => ['original_text' => '', 'input_type' => 'manual_entry'],
            'manual_entry' => true,
            'final_product_code' => '',
            'selected_product_code' => '',
            'line_note' => '',
            'length' => ['number_of_lengths' => 1, 'length_each' => '', 'total_length' => '', 'unit' => 'm'],
            'unit_price' => '',
            'margin_percent' => 0,
            'final_unit_price' => '',
            'line_total' => '',
            'status' => 'matched',
            'final_status' => 'matched',
            'confidence' => 0,
            'candidates' => [],
            'warnings' => [],
            'pricing' => self::pricingPlaceholder('missing_code', $region),
            'line_uid' => $uid,
        ];
    }

    /**
     * `isBlankManualRow`: no code, note or length.
     *
     * @param array<string, mixed> $row
     */
    private static function isBlank(array $row): bool
    {
        $length = DraftChangeLog::object($row['length'] ?? null);

        return self::productCode($row) === ''
            && PythonText::strip(PythonText::str($row['line_note'] ?? '')) === ''
            && PythonText::strip(PythonText::str($length['length_each'] ?? '')) === '';
    }

    /**
     * `pricingPlaceholder`. The debtor company is left to pricing, which knows it.
     *
     * @return array{status: string, source: string, region: string}
     */
    private static function pricingPlaceholder(string $status, string $region): array
    {
        return ['status' => $status, 'source' => 'tricab_cost_api', 'region' => $region];
    }

    /**
     * `clearMakeMoq`: the MOQ and prepack notes, the quantity warnings, and `make_moq`.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function clearMakeMoq(array $row): array
    {
        $prepack = (DraftChangeLog::object($row['make_moq'] ?? null)['source'] ?? null) === 'prepack_size';
        $row = self::clearNoteSegments($row, static function (string $segment) use ($prepack): bool {
            foreach (self::QUANTITY_NOTE_PREFIXES as $prefix) {
                if (str_starts_with($segment, $prefix)) {
                    return true;
                }
            }

            return preg_match(self::MAKE_MOQ_NOTE_RE, $segment) === 1
                || preg_match(self::PREPACK_ROUNDING_NOTE_RE, $segment) === 1
                || ($prepack && preg_match(self::PREPACK_BREAKDOWN_NOTE_RE, $segment) === 1);
        });
        if (is_array($row['warnings'] ?? null)) {
            $row['warnings'] = array_values(array_filter($row['warnings'], static function (mixed $warning): bool {
                $text = PythonText::str($warning ?? '');
                foreach (self::QUANTITY_WARNING_PREFIXES as $prefix) {
                    if (str_starts_with($text, $prefix)) {
                        return false;
                    }
                }

                return true;
            }));
        }
        unset($row['make_moq']);

        return $row;
    }

    /**
     * `clearCutsNote` then `applyCutsNote`: the "(4 x 55)" breakdown follows the
     * quantity, space-joined as the parser writes it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function applyCutsNote(array $row, float $cuts, ?float $each): array
    {
        $row = self::clearNoteSegments($row, static fn (string $segment): bool => str_starts_with($segment, self::CUTS_NOTE_PREFIX)
            || (preg_match(self::CUTS_NOTE_RE, $segment, $m) === 1 && $m[0] === trim($segment)));
        $note = PythonText::str($row['line_note'] ?? '');
        $stripped = trim((string) preg_replace('/\s{2,}/', ' ', (string) preg_replace(self::CUTS_NOTE_RE, '', $note)));
        if ($stripped !== $note) {
            $note = trim((string) preg_replace('/\s+-\s*$/', '', $stripped));
        }
        if ($cuts > 1 && $each !== null && $each > 0) {
            $breakdown = sprintf('(%s x %s)', self::format($cuts), self::format($each));
            $note = $note !== '' ? $note . ' ' . $breakdown : $breakdown;
        }
        $row['line_note'] = $note;

        return $row;
    }

    /**
     * `clearLineNoteSegment`: drop the " - "-joined segments the predicate names.
     *
     * @param array<string, mixed>     $row
     * @param callable(string): bool   $drop
     *
     * @return array<string, mixed>
     */
    private static function clearNoteSegments(array $row, callable $drop): array
    {
        $parts = preg_split('/\s+-\s+/', PythonText::str($row['line_note'] ?? ''));
        $segments = array_values(array_filter(array_map('trim', $parts === false ? [] : $parts), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return $row;
        }
        $kept = array_values(array_filter($segments, static fn (string $s): bool => !$drop($s)));
        if (count($kept) !== count($segments)) {
            $row['line_note'] = implode(' - ', $kept);
        }

        return $row;
    }

    /** `formatLengthNumber`: whole numbers bare, others to five places. */
    private static function format(float $value): string
    {
        $rounded = round($value, 5);
        if (floor($rounded) === $rounded) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(sprintf('%.5F', $rounded), '0'), '.');
    }

    /** A decimal string as the JSON number the source stores: an int when whole. */
    private static function number(string $decimal): int|float
    {
        $value = round((float) $decimal, 5);

        return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }

    private static function same(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) < 0.000005;
    }

    /** @param array<string, mixed> $row */
    private static function excluded(array $row): bool
    {
        return DraftChangeLog::truthy($row['directive_excluded'] ?? null) || ($row['disposition'] ?? null) === 'excluded';
    }

    /**
     * What the rep settles by approving: what was already confirmed, then the
     * row's inferred fields and its warnings, each once, in that order.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function confirmed(array $row): array
    {
        $out = [];
        foreach (['confirmed_fields', 'inferred_fields', 'warnings'] as $key) {
            $values = $row[$key] ?? null;
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_string($value) && $value !== '' && !in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }
}
