<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * The register's gate for a model-read capture (`_grade_extraction` and `_apply_extraction_grade`, T6.4 B2): a
 * confidence, and in plain words what is wrong with the capture. Two differences from the source, both ruled in the
 * specification: the sums are compared in whole cents (the source compared floats, so a difference of exactly 0.02
 * could flip with float noise), and the percentage rounds half up (the source rounded half to even).
 */
final class ApGrading
{
    /** `INVOICE_CONFIDENCE_THRESHOLD`: strictly below it a capture goes to Review. */
    public const DEFAULT_THRESHOLD = 0.80;

    /** `AMOUNT_TOLERANCE` (0.02) in cents. */
    public const TOLERANCE_CENTS = 2;

    /**
     * The confidence (`extraction_confidence`, else `classification_confidence`, clamped to 0..1; 0.0 when absent or
     * unreadable: absent is never read as certain) and the review reason (null when the capture looks sound), the reasons
     * in this order, joined with "; ":
     *
     * 1. `Could not read the invoice number, vendor, total` (whichever of the three are empty, 0 included);
     * 2. `Low extraction confidence (41%)` when the confidence is strictly below the threshold;
     * 3. `Amounts do not add up: 100.00 + 10.00 = 110.00, but the total reads 210.00` when net, tax and total are all
     *    numbers and net + tax is more than two cents from the total. A missing amount is unknown, not wrong.
     *
     * @param array<array-key, mixed> $fields
     * @return array{0: float, 1: ?string}
     */
    public static function grade(array $fields, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $raw = $fields['extraction_confidence'] ?? null;
        if ($raw === null) {
            $raw = $fields['classification_confidence'] ?? null;
        }
        $number = self::number($raw);
        $confidence = $number === null || is_nan($number) ? 0.0 : min(max($number, 0.0), 1.0);

        $reasons = [];
        $missing = [];
        foreach ([['invoice number', 'invoice_no'], ['vendor', 'vendor'], ['total', 'total_amount']] as [$label, $key]) {
            if (!ApRules::truthy($fields[$key] ?? null)) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $reasons[] = 'Could not read the ' . implode(', ', $missing);
        }
        if ($confidence < $threshold) {
            $reasons[] = sprintf('Low extraction confidence (%d%%)', (int) round($confidence * 100, 0, PHP_ROUND_HALF_UP));
        }
        $net = self::number($fields['net_amount'] ?? null);
        $tax = self::number($fields['tax_amount'] ?? null);
        $total = self::number($fields['total_amount'] ?? null);
        if ($net !== null && $tax !== null && $total !== null && is_finite($net) && is_finite($tax) && is_finite($total)) {
            $netCents = self::cents($net);
            $taxCents = self::cents($tax);
            $totalCents = self::cents($total);
            if (abs(($netCents + $taxCents) - $totalCents) > self::TOLERANCE_CENTS) {
                $reasons[] = sprintf(
                    'Amounts do not add up: %s + %s = %s, but the total reads %s',
                    self::money($netCents),
                    self::money($taxCents),
                    self::money($netCents + $taxCents),
                    self::money($totalCents),
                );
            }
        }

        return [$confidence, $reasons === [] ? null : implode('; ', $reasons)];
    }

    /** A number rounded half up to whole cents (`round`, away from zero on .5). */
    public static function cents(float $amount): int
    {
        // Rounded to two places first, which reads the decimal the person wrote (1.005 is 1.01), then to whole cents.
        return (int) round(round($amount, 2, PHP_ROUND_HALF_UP) * 100);
    }

    /** Python's `f"{value:,.2f}"` for whole cents. */
    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . number_format(intdiv($abs, 100), 0, '.', ',') . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Python's `float(value)` for a JSON value: numbers and numeric text, a boolean as 1 and 0; null otherwise.
     */
    public static function number(mixed $value): ?float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $text = trim($value);
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text) === 1) {
                return (float) $text;
            }
        }

        return null;
    }

    /**
     * The status and reason a manual capture starts with (`_apply_extraction_grade`).
     *
     * - A body with neither confidence key was typed by a person: no grading, the reason is whatever the body carried,
     *   and the status is Needs Review only when the body has none.
     * - Otherwise `extraction_confidence` is the clamped confidence and the reason is the grade's; the status defaults to
     *   In Progress, and with a reason an In Progress status becomes Needs Review. A status the caller sent is kept.
     *
     * @param array<array-key, mixed> $body
     * @return array{status: string, confidence: ?float, review_reason: ?string, graded: bool}
     */
    public static function forManualCapture(array $body, ?string $bodyReason, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $sentStatus = ApRules::truthy($body['status'] ?? null) && is_string($body['status']) ? $body['status'] : null;
        if (!array_key_exists('extraction_confidence', $body) && !array_key_exists('classification_confidence', $body)) {
            return [
                'status' => $sentStatus ?? ApInvoice::STATUS_NEEDS_REVIEW,
                'confidence' => null,
                'review_reason' => $bodyReason,
                'graded' => false,
            ];
        }
        [$confidence, $reason] = self::grade($body, $threshold);
        $status = $sentStatus ?? ApInvoice::STATUS_IN_PROGRESS;
        if ($reason !== null && $status === ApInvoice::STATUS_IN_PROGRESS) {
            $status = ApInvoice::STATUS_NEEDS_REVIEW;
        }

        return ['status' => $status, 'confidence' => $confidence, 'review_reason' => $reason, 'graded' => true];
    }
}
