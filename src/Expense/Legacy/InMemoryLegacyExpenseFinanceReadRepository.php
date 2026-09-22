<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;

/**
 * In-memory double for Finance legacy parity tests.
 *
 * @phpstan-type LegacyRow array<string, mixed>
 */
final class InMemoryLegacyExpenseFinanceReadRepository implements LegacyExpenseFinanceReadRepository
{
    /** @param list<LegacyRow> $receipts */
    public function __construct(
        private array $receipts = [],
        /** @var list<array{periodId: int|null, country: string|null, matchStatus: string|null, resolution: string|null, entryCount: int}> */
        private array $bankFeedCounts = [],
        /** @var list<array{periodId: int, country: string|null, status: string|null, periodCount: int}> */
        private array $periodCounts = [],
    ) {
    }

    public function findById(int $legacyReceiptId): ?array
    {
        foreach ($this->receipts as $row) {
            if (MixedValueCoercion::intValue($row['id'] ?? 0) === $legacyReceiptId && empty($row['deleted_at'])) {
                return $row;
            }
        }

        return null;
    }

    public function listFinance(LegacyExpenseFinanceFilters $filters): array
    {
        $matched = $this->filter($filters);
        $offset = max(0, $filters->offset);
        $limit = max(1, min(1000, $filters->limit));

        return array_slice($matched, $offset, $limit);
    }

    public function countFinance(LegacyExpenseFinanceFilters $filters): int
    {
        return count($this->filter($filters));
    }

    public function countByStatus(?string $dateFrom, ?string $dateTo): array
    {
        $filters = new LegacyExpenseFinanceFilters(dateFrom: $dateFrom, dateTo: $dateTo, excludeDraft: false);
        $out = [];
        foreach ($this->filter($filters) as $row) {
            $status = strtolower(MixedValueCoercion::stringValue($row['status'] ?? 'unknown'));
            $out[$status] = ($out[$status] ?? 0) + 1;
        }

        return $out;
    }

    public function sumByCurrency(LegacyExpenseFinanceFilters $filters): array
    {
        $buckets = [];
        foreach ($this->filter($filters) as $row) {
            $currency = strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD';
            if (!isset($buckets[$currency])) {
                $buckets[$currency] = ['total' => 0.0, 'count' => 0];
            }
            $buckets[$currency]['total'] += MixedValueCoercion::floatValue($row['total_amount'] ?? 0);
            $buckets[$currency]['count']++;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $currency => $data) {
            $out[] = [
                'currency' => $currency,
                'totalAmount' => number_format($data['total'], 2, '.', ''),
                'receiptCount' => $data['count'],
            ];
        }

        return $out;
    }

    public function countBankFeedByPeriodCountryStatus(): array
    {
        return $this->bankFeedCounts;
    }

    public function countPeriodsByCountryStatus(): array
    {
        return $this->periodCounts;
    }

    /**
     * @return list<LegacyRow>
     */
    private function filter(LegacyExpenseFinanceFilters $filters): array
    {
        $ownerKeys = [];
        foreach ($filters->ownerKeys ?? [] as $key) {
            $normalized = strtolower(trim($key));
            if ($normalized !== '') {
                $ownerKeys[$normalized] = true;
            }
        }

        $out = [];
        foreach ($this->receipts as $row) {
            if (!empty($row['deleted_at'])) {
                continue;
            }
            $status = strtolower(MixedValueCoercion::stringValue($row['status'] ?? ''));
            if ($filters->excludeDraft === true && $status === 'draft') {
                continue;
            }
            $createdBy = strtolower(MixedValueCoercion::stringValue($row['created_by'] ?? ''));
            if ($ownerKeys !== [] && !isset($ownerKeys[$createdBy])) {
                continue;
            }
            $date = substr(MixedValueCoercion::stringValue($row['receipt_date'] ?? ''), 0, 10);
            if ($filters->dateFrom !== null && $date !== '' && $date < substr($filters->dateFrom, 0, 10)) {
                continue;
            }
            if ($filters->dateTo !== null && $date !== '' && $date > substr($filters->dateTo, 0, 10)) {
                continue;
            }
            if ($filters->status !== null && $filters->status !== '' && $status !== strtolower(trim($filters->status))) {
                continue;
            }
            $currency = strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD';
            if ($filters->currency !== null && $filters->currency !== '' && $currency !== strtoupper(trim($filters->currency))) {
                continue;
            }
            if ($filters->customer !== null && trim($filters->customer) !== '') {
                $needle = strtolower(trim($filters->customer));
                $hay = strtolower(
                    MixedValueCoercion::stringValue($row['customer_code'] ?? '')
                    . ' '
                    . MixedValueCoercion::stringValue($row['customer_name'] ?? ''),
                );
                if (!str_contains($hay, $needle)) {
                    continue;
                }
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
            if ($filters->unmatchedOnly === true) {
                $bankId = MixedValueCoercion::intValue($row['bank_feed_id'] ?? 0);
                if ($bankId > 0 || ($resolution !== '' && $resolution !== 'chased')) {
                    continue;
                }
            }
            if ($filters->search !== null && trim($filters->search) !== '') {
                $needle = strtolower(trim($filters->search));
                $hay = strtolower(implode(' ', [
                    MixedValueCoercion::stringValue($row['vendor_name'] ?? ''),
                    MixedValueCoercion::stringValue($row['created_by'] ?? ''),
                    MixedValueCoercion::stringValue($row['description'] ?? ''),
                    MixedValueCoercion::stringValue($row['cost_centre'] ?? ''),
                ]));
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $da = MixedValueCoercion::stringValue($a['receipt_date'] ?? '');
            $db = MixedValueCoercion::stringValue($b['receipt_date'] ?? '');
            if ($da === $db) {
                return MixedValueCoercion::intValue($b['id'] ?? 0) <=> MixedValueCoercion::intValue($a['id'] ?? 0);
            }

            return $db <=> $da;
        });

        return $out;
    }
}
