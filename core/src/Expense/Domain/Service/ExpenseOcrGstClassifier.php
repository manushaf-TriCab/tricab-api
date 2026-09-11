<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use TriCab\Core\Expense\Module;

/**
 * Decides whether an OCR-extracted GST figure looks GST-inclusive.
 *
 * Legacy rule, reproduced exactly: a GST amount within 0.05 of total/11 means
 * the vendor printed a GST-inclusive total (1/11th of an inclusive total is the
 * GST component at 10%). Anything else is treated as exclusive.
 *
 * The classifier only ever produces a suggestion. `suspicious` marks figures a
 * human must look at rather than silently reshaping them.
 */
final class ExpenseOcrGstClassifier
{
    /** Legacy tolerance in currency units. */
    public const TOLERANCE = '0.05';

    /** GST-inclusive divisor for a 10% rate. */
    public const INCLUSIVE_DIVISOR = '11';

    private const SCALE = 6;

    /**
     * @return array{gstType: string, suspicious: bool, reason: string}
     */
    public static function classify(?string $totalAmount, ?string $gstAmount): array
    {
        $total = self::decimalOrNull($totalAmount);
        if ($total === null) {
            return self::result(Module::GST_UNKNOWN, true, 'total_missing_or_invalid');
        }
        if (bccomp($total, '0', self::SCALE) <= 0) {
            return self::result(Module::GST_UNKNOWN, true, 'total_not_positive');
        }

        $gst = self::decimalOrNull($gstAmount);
        if ($gst === null) {
            return self::result(Module::GST_UNKNOWN, false, 'gst_missing');
        }
        if (bccomp($gst, '0', self::SCALE) < 0) {
            return self::result(Module::GST_UNKNOWN, true, 'gst_negative');
        }

        // A GST component can never equal or exceed the total it is part of.
        if (bccomp($gst, $total, self::SCALE) >= 0) {
            return self::result(Module::GST_UNKNOWN, true, 'gst_not_less_than_total');
        }

        $expectedInclusive = bcdiv($total, self::INCLUSIVE_DIVISOR, self::SCALE);
        $delta = self::absolute(bcsub($gst, $expectedInclusive, self::SCALE));
        if (bccomp($delta, self::TOLERANCE, self::SCALE) <= 0) {
            return self::result(Module::GST_INCLUSIVE, false, 'within_legacy_inclusive_tolerance');
        }

        if (bccomp($gst, '0', self::SCALE) === 0) {
            return self::result(Module::GST_EXCLUSIVE, false, 'zero_gst');
        }

        return self::result(Module::GST_EXCLUSIVE, false, 'outside_legacy_inclusive_tolerance');
    }

    /**
     * @return array{gstType: string, suspicious: bool, reason: string}
     */
    private static function result(string $gstType, bool $suspicious, string $reason): array
    {
        return ['gstType' => $gstType, 'suspicious' => $suspicious, 'reason' => $reason];
    }

    private static function decimalOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim(str_replace([',', ' ', '$'], '', $value));
        if ($normalized === '' || preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private static function absolute(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }
}
