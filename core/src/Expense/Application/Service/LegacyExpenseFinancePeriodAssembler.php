<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Module;

/**
 * Maps a legacy MySQL tc.triexpense_periods row into the shared Finance period view.
 */
final class LegacyExpenseFinancePeriodAssembler
{
    private const COUNTRY_LABELS = [
        Module::COUNTRY_AU => 'Australia',
        Module::COUNTRY_NZ => 'New Zealand',
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toFinanceView(array $row): array
    {
        $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
        $publicId = LegacyExpensePeriodRef::fromLegacyId($legacyId)->toPublicId();

        $country = strtoupper(MixedValueCoercion::stringValue($row['country'] ?? '')) ?: null;
        $periodStart = $this->dateOnly($row['period_start'] ?? null);
        $periodEnd = $this->dateOnly($row['period_end'] ?? null);
        $status = strtolower(MixedValueCoercion::stringValue($row['status'] ?? 'open')) ?: 'open';

        return [
            'publicId' => $publicId,
            'id' => $legacyId > 0 ? $legacyId : null,
            'country' => $country,
            'label' => self::label($periodStart, $periodEnd, $country),
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'transactionStartDate' => $this->dateOnly($row['tx_start'] ?? null),
            'transactionEndDate' => $this->dateOnly($row['tx_end'] ?? null),
            'status' => $status,
            'closedAt' => $this->atomOrNull($row['closed_at'] ?? null),
            'closedBy' => MixedValueCoercion::nullableString($row['closed_by'] ?? null),
            'closedByUserId' => null,
            'createdAtUtc' => $this->atomOrNull($row['created_at'] ?? null),
            'rowVersion' => null,
            'source' => 'legacy',
            'mutable' => false,
        ];
    }

    /**
     * "May–Jul 2026 — Australia" (single month collapses to "May 2026 — Australia").
     */
    public static function label(?string $periodStart, ?string $periodEnd, ?string $country): string
    {
        $countryLabel = $country !== null ? (self::COUNTRY_LABELS[$country] ?? $country) : null;

        $start = self::parse($periodStart);
        $end = self::parse($periodEnd);
        if ($start === null && $end === null) {
            return $countryLabel ?? 'Legacy period';
        }
        $start ??= $end;
        $end ??= $start;

        if ($start->format('Y-m') === $end->format('Y-m')) {
            $range = $start->format('M Y');
        } elseif ($start->format('Y') === $end->format('Y')) {
            $range = $start->format('M') . '–' . $end->format('M Y');
        } else {
            $range = $start->format('M Y') . '–' . $end->format('M Y');
        }

        return $countryLabel !== null ? $range . ' — ' . $countryLabel : $range;
    }

    private static function parse(?string $date): ?DateTimeImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = MixedValueCoercion::stringValue($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) === 1) {
            return substr($m[0], 0, 10);
        }
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function atomOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable(MixedValueCoercion::stringValue($value)))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
