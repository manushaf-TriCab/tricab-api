<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * What the draft change log watches, and how it reads a value: the source
 * store's `_tracked_row_values`, `_line_summary` and `_header_changes`,
 * ported exactly (`draft_store.py`).
 *
 * `rfq_draft_row_edits` is parser-accuracy evidence as well as history: a row
 * edit whose `from_system` is 1 is somebody moving the parser off its own
 * answer. Reading a field differently from the source would make migrated and
 * new edits disagree about what changed, so the parity fixture pins this.
 */
final class DraftChangeLog
{
    public const TRACKED_ROW_FIELDS = [
        'product_code', 'quantity', 'cuts', 'length_each', 'base_cost',
        'margin_percent', 'sell_price', 'comment', 'status', 'description',
    ];

    public const TRACKED_HEADER_FIELDS = [
        'customer_code', 'customer_name', 'contact', 'phone', 'cusref', 'author',
        'salesoffice', 'payterms', 'validtill', 'comment', 'region',
    ];

    /** A whole line arriving or leaving is one edit against this pseudo-field. */
    public const LINE_PRESENCE_FIELD = 'line';

    /** Every row key that holds an ERP product code; uppercased on write. */
    public const PRODUCT_CODE_KEYS = ['final_product_code', 'selected_product_code', 'parent_product_code'];

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    public static function trackedValues(array $row): array
    {
        $length = self::object($row['length'] ?? null);
        $source = self::object($row['source'] ?? null);
        // Cables carry a computed total; accessories leave it empty and count
        // in units, and then the unit count is the quantity, not the cuts.
        $quantity = $length['total_length'] ?? null;
        $cuts = $length['number_of_lengths'] ?? null;
        if ($quantity === null || $quantity === '') {
            $quantity = $cuts;
            $cuts = null;
        }
        $lengthEach = $length['length_each'] ?? null;
        if ($lengthEach === null || $lengthEach === '') {
            $lengthEach = $length['length_each_m'] ?? null;
        }

        return [
            'product_code' => PythonText::editText(self::or($row['final_product_code'] ?? null, $row['selected_product_code'] ?? null)),
            'quantity' => PythonText::editText($quantity),
            'cuts' => PythonText::editText($cuts),
            'length_each' => PythonText::editText($lengthEach),
            'base_cost' => PythonText::editText($row['unit_price'] ?? null),
            'margin_percent' => PythonText::editText($row['margin_percent'] ?? null),
            'sell_price' => PythonText::editText($row['final_unit_price'] ?? null),
            'comment' => PythonText::editText($row['line_note'] ?? null),
            'status' => PythonText::editText(self::or($row['final_status'] ?? null, $row['status'] ?? null)),
            'description' => PythonText::editText(self::or($source['original_text'] ?? null, $row['raw_text'] ?? null)),
        ];
    }

    /**
     * One-line identity for a line being added or removed, at most 200
     * characters.
     *
     * @param array<string, mixed> $row
     */
    public static function lineSummary(array $row): string
    {
        $values = self::trackedValues($row);
        $label = $values['product_code'] !== '' ? $values['product_code']
            : ($values['description'] !== '' ? $values['description'] : '(blank line)');
        $summary = $values['quantity'] !== '' ? "{$label} x {$values['quantity']}" : $label;

        return mb_substr($summary, 0, 200, 'UTF-8');
    }

    /**
     * Before/after for the header fields that changed on a save.
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     *
     * @return list<array{field: string, from: string, to: string}>
     */
    public static function headerChanges(array $previous, array $current): array
    {
        $changes = [];
        foreach (self::TRACKED_HEADER_FIELDS as $field) {
            $before = PythonText::editText($previous[$field] ?? null);
            $after = PythonText::editText($current[$field] ?? null);
            if ($before !== $after) {
                $changes[] = ['field' => $field, 'from' => $before, 'to' => $after];
            }
        }

        return $changes;
    }

    /**
     * The source's `_normalise_product_codes`: a key that is present stays
     * present, uppercased and stripped; an absent key is not added.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function normaliseProductCodes(array $row): array
    {
        foreach (self::PRODUCT_CODE_KEYS as $key) {
            if (array_key_exists($key, $row)) {
                $value = self::truthy($row[$key]) ? $row[$key] : '';
                $row[$key] = PythonText::upper(PythonText::strip(PythonText::str($value)));
            }
        }

        return $row;
    }

    /** Python truthiness, for `a or b`. */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === '', $value === 0, $value === [] => false,
            is_float($value) => $value != 0.0,
            $value instanceof \stdClass => get_object_vars($value) !== [],
            default => true,
        };
    }

    /** Python's `a or b`. */
    private static function or(mixed $a, mixed $b): mixed
    {
        return self::truthy($a) ? $a : $b;
    }

    /**
     * `row.get(k) if isinstance(row.get(k), dict) else {}`.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }
        /** @var array<string, mixed> $value */
        return $value;
    }
}
