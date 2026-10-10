<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * Reads the fields of a create or a PATCH body (T6.4 B3, B7): only the fixed list of columns a caller may write, every
 * other key dropped silently as the source dropped unknown keys, each value checked and put in the form the table holds.
 *
 * Messages kept word for word from the source: `Invalid status: 'Approved'`, `Invalid duplicate_status: 'Maybe'` (a null
 * prints `None`), `invoice_date is not a date: 'Net 30'`. The platform adds `<field> must be text`, `<field> must be at most
 * N characters`, `<field> must be a number` for values the table cannot hold.
 */
final class ApInvoiceInput
{
    /** The largest amount `DECIMAL(18,2)` holds, less a margin. */
    private const MAX_AMOUNT = 9999999999999999.0;

    private const DATE_FIELDS = ['invoice_date', 'due_date'];
    private const TEXT_FIELDS = ['invoice_no', 'vendor', 'vendor_email', 'tax_id', 'po_number', 'currency', 'company', 'account_category', 'cost_centre', 'duplicate_reason'];
    private const LONG_TEXT_FIELDS = ['notes', 'review_reason'];
    private const AMOUNT_FIELDS = ['net_amount', 'tax_amount', 'total_amount'];

    /**
     * The writable columns present in the body, checked.
     *
     * On a create (`$forCreate`) a missing, null or empty `status` means "none sent" and is left out for the grade to default;
     * on a PATCH a `status` that is present must be one of the four stored values, null included.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> column name => value
     */
    public static function columns(array $body, bool $forCreate): array
    {
        if (array_key_exists('status', $body) && (!$forCreate || ApRules::truthy($body['status']))) {
            if (!is_string($body['status']) || !in_array($body['status'], ApInvoice::STORED_STATUSES, true)) {
                throw self::invalid('status', $body['status']);
            }
        }
        if (array_key_exists('duplicate_status', $body)
            && (!is_string($body['duplicate_status']) || !in_array($body['duplicate_status'], ApInvoice::DUPLICATE_STATUSES, true))) {
            throw self::invalid('duplicate_status', $body['duplicate_status']);
        }

        $out = [];
        foreach (self::DATE_FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null || $value === '') {
                $out[$field] = null;

                continue;
            }
            $normalised = ApDates::normalize($value);
            if ($normalised === null) {
                throw new ApInvalid($field . ' is not a date: ' . self::repr($value));
            }
            $out[$field] = $normalised;
        }
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $out[$field] = self::text($field, $body[$field], ApInvoice::TEXT_LIMITS[$field] ?? null) ?? '';
            }
        }
        foreach (self::LONG_TEXT_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $out[$field] = self::text($field, $body[$field], null);
            }
        }
        foreach (self::AMOUNT_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $out[$field] = self::amount($field, $body[$field]);
            }
        }
        if (array_key_exists('status', $body) && !($forCreate && !ApRules::truthy($body['status']))) {
            $out['status'] = $body['status'];
        }
        if (array_key_exists('duplicate_status', $body)) {
            $out['duplicate_status'] = $body['duplicate_status'];
        }
        if (array_key_exists('is_revised', $body)) {
            $out['is_revised'] = ApRules::truthy($body['is_revised']);
        }

        return $out;
    }

    /**
     * A text value as the column holds it. Null is none; a number or boolean becomes text (a boolean as Python's `True`);
     * a list or an object is refused. `$limit` is the column's width in characters, null for unbounded.
     */
    public static function text(string $field, mixed $value, ?int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new ApInvalid($field . ' must be text');
        }
        $text = is_bool($value) ? ($value ? 'True' : 'False') : (string) $value;
        if ($limit !== null && mb_strlen($text) > $limit) {
            throw new ApInvalid($field . ' must be at most ' . $limit . ' characters');
        }

        return $text;
    }

    /** An amount rounded half up to cents; null stays null. Text that is not a number, and an amount the column cannot hold, are refused. */
    public static function amount(string $field, mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = is_bool($value) ? null : ApGrading::number($value);
        if ($number === null || !is_finite($number)) {
            throw new ApInvalid($field . ' must be a number');
        }
        if (abs($number) > self::MAX_AMOUNT) {
            throw new ApInvalid($field . ' is too large');
        }

        return ApGrading::cents($number) / 100;
    }

    private static function invalid(string $field, mixed $value): ApInvalid
    {
        return new ApInvalid('Invalid ' . $field . ': ' . self::repr($value));
    }

    /** Python's `repr()` of a JSON value, which is how the source's messages quote what a caller sent. */
    public static function repr(mixed $value): string
    {
        return match (true) {
            $value === null => 'None',
            is_bool($value) => $value ? 'True' : 'False',
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value && abs($value) < 1e16 ? sprintf('%.1f', $value) : (string) $value,
            is_string($value) => self::quote($value),
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?',
        };
    }

    private static function quote(string $value): string
    {
        $escaped = str_replace(['\\', "\n", "\r", "\t"], ['\\\\', '\\n', '\\r', '\\t'], $value);
        if (str_contains($escaped, "'") && !str_contains($escaped, '"')) {
            return '"' . $escaped . '"';
        }

        return "'" . str_replace("'", "\\'", $escaped) . "'";
    }
}
