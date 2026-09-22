<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;

/**
 * In-memory double for Finance legacy bank/period parity tests.
 *
 * @phpstan-type LegacyRow array<string, mixed>
 */
final class InMemoryLegacyExpenseFinanceBankReadRepository implements LegacyExpenseFinanceBankReadRepository
{
    /**
     * @param list<LegacyRow> $bankRows
     * @param list<LegacyRow> $periodRows
     */
    public function __construct(
        private array $bankRows = [],
        private array $periodRows = [],
    ) {
    }

    public function findBankById(int $legacyBankFeedId): ?array
    {
        foreach ($this->bankRows as $row) {
            if (MixedValueCoercion::intValue($row['id'] ?? 0) === $legacyBankFeedId) {
                return $row;
            }
        }

        return null;
    }

    public function listBank(LegacyExpenseFinanceBankFilters $filters): array
    {
        return array_slice(
            $this->filter($filters),
            $filters->normalizedOffset(),
            $filters->normalizedLimit(),
        );
    }

    public function countBank(LegacyExpenseFinanceBankFilters $filters): int
    {
        return count($this->filter($filters));
    }

    public function sumBankByCurrency(LegacyExpenseFinanceBankFilters $filters): array
    {
        $buckets = [];
        foreach ($this->filter($filters) as $row) {
            $currency = strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD';
            if (!isset($buckets[$currency])) {
                $buckets[$currency] = ['total' => 0.0, 'count' => 0];
            }
            $buckets[$currency]['total'] += MixedValueCoercion::floatValue($row['amount'] ?? 0);
            $buckets[$currency]['count']++;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $currency => $data) {
            $out[] = [
                'currency' => $currency,
                'totalAmount' => number_format($data['total'], 2, '.', ''),
                'entryCount' => $data['count'],
            ];
        }

        return $out;
    }

    public function findPeriodById(int $legacyPeriodId): ?array
    {
        foreach ($this->periodRows as $row) {
            if (MixedValueCoercion::intValue($row['id'] ?? 0) === $legacyPeriodId) {
                return $row;
            }
        }

        return null;
    }

    public function listPeriods(?string $country): array
    {
        $wanted = $country !== null && trim($country) !== '' ? strtoupper(trim($country)) : null;
        $out = [];
        foreach ($this->periodRows as $row) {
            $rowCountry = strtoupper(MixedValueCoercion::stringValue($row['country'] ?? ''));
            if ($wanted !== null && $rowCountry !== $wanted) {
                continue;
            }
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $ea = MixedValueCoercion::stringValue($a['period_end'] ?? '');
            $eb = MixedValueCoercion::stringValue($b['period_end'] ?? '');
            if ($ea === $eb) {
                return MixedValueCoercion::intValue($b['id'] ?? 0) <=> MixedValueCoercion::intValue($a['id'] ?? 0);
            }

            return $eb <=> $ea;
        });

        return $out;
    }

    public function countBankByPeriodCountryMatchResolution(): array
    {
        /** @var array<string, array{periodId: int|null, country: string|null, matchStatus: string|null, resolution: string|null, entryCount: int}> $census */
        $census = [];
        foreach ($this->bankRows as $row) {
            $periodId = MixedValueCoercion::nullableInt($row['period_id'] ?? null);
            $country = MixedValueCoercion::nullableString($row['country'] ?? null);
            $matchStatus = MixedValueCoercion::nullableString($row['match_status'] ?? null);
            $resolution = MixedValueCoercion::nullableString($row['resolution'] ?? null);
            $key = ($periodId ?? 0) . '|' . ($country ?? '') . '|' . ($matchStatus ?? '') . '|' . ($resolution ?? '');
            if (!isset($census[$key])) {
                $census[$key] = [
                    'periodId' => $periodId,
                    'country' => $country,
                    'matchStatus' => $matchStatus,
                    'resolution' => $resolution,
                    'entryCount' => 0,
                ];
            }
            $census[$key]['entryCount']++;
        }

        return array_values($census);
    }

    /**
     * @return list<LegacyRow>
     */
    private function filter(LegacyExpenseFinanceBankFilters $filters): array
    {
        $out = [];
        foreach ($this->bankRows as $row) {
            if ($filters->periodId !== null && $filters->periodId > 0
                && MixedValueCoercion::nullableInt($row['period_id'] ?? null) !== $filters->periodId) {
                continue;
            }
            $country = strtoupper(MixedValueCoercion::stringValue($row['country'] ?? ''));
            if ($filters->country !== null && trim($filters->country) !== ''
                && $country !== strtoupper(trim($filters->country))) {
                continue;
            }
            $date = substr(MixedValueCoercion::stringValue($row['transaction_date'] ?? ''), 0, 10);
            if ($filters->dateFrom !== null && $date !== '' && $date < substr(trim($filters->dateFrom), 0, 10)) {
                continue;
            }
            if ($filters->dateTo !== null && $date !== '' && $date > substr(trim($filters->dateTo), 0, 10)) {
                continue;
            }
            $currency = strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD';
            if ($filters->currency !== null && trim($filters->currency) !== ''
                && $currency !== strtoupper(trim($filters->currency))) {
                continue;
            }
            $matchStatus = strtolower(MixedValueCoercion::stringValue($row['match_status'] ?? ''));
            if ($filters->matchStatus !== null && trim($filters->matchStatus) !== ''
                && $matchStatus !== strtolower(trim($filters->matchStatus))) {
                continue;
            }
            $resolution = strtolower(MixedValueCoercion::stringValue($row['resolution'] ?? ''));
            if ($filters->resolution !== null && trim($filters->resolution) !== '') {
                $want = strtolower(trim($filters->resolution));
                if ($want === 'none' || $want === 'unresolved') {
                    if ($resolution !== '') {
                        continue;
                    }
                } elseif ($resolution !== $want) {
                    continue;
                }
            }
            if ($filters->cardholderEmail !== null && trim($filters->cardholderEmail) !== ''
                && strtolower(MixedValueCoercion::stringValue($row['cardholder_email'] ?? ''))
                    !== strtolower(trim($filters->cardholderEmail))) {
                continue;
            }
            if ($filters->needsReceiptOnly === true) {
                if (!in_array($matchStatus, ['unmatched', 'ambiguous'], true)) {
                    continue;
                }
                if ($resolution !== '' && $resolution !== 'chased') {
                    continue;
                }
            }
            if ($filters->search !== null && trim($filters->search) !== '') {
                $needle = strtolower(trim($filters->search));
                $hay = strtolower(implode(' ', [
                    MixedValueCoercion::stringValue($row['merchant_name'] ?? ''),
                    MixedValueCoercion::stringValue($row['cardholder_email'] ?? ''),
                    MixedValueCoercion::stringValue($row['bank_reference'] ?? ''),
                    MixedValueCoercion::stringValue($row['merchant_city'] ?? ''),
                ]));
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $da = MixedValueCoercion::stringValue($a['transaction_date'] ?? '');
            $db = MixedValueCoercion::stringValue($b['transaction_date'] ?? '');
            if ($da === $db) {
                return MixedValueCoercion::intValue($b['id'] ?? 0) <=> MixedValueCoercion::intValue($a['id'] ?? 0);
            }

            return $db <=> $da;
        });

        return $out;
    }
}
