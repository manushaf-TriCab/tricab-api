<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryExpensePeriodRepository implements ExpensePeriodRepository
{
    /** @var array<string, ExpensePeriod> */
    private array $byPublicId = [];

    private int $nextId = 1;

    private int $rowSeq = 1;

    public function save(ExpensePeriod $period, ?RowVersionToken $expectedRowVersion = null): ExpensePeriod
    {
        $key = $period->publicId()->toString();
        $existing = $this->byPublicId[$key] ?? null;

        if ($expectedRowVersion !== null) {
            $current = $existing?->rowVersion();
            if ($current === null || !$current->equals($expectedRowVersion)) {
                throw ExpenseConcurrencyConflict::forPeriod();
            }
        }

        $saved = $period->id() === null ? $period->withId($this->nextId++) : $period;
        $saved = $saved->withRowVersion($this->nextRowVersion());
        $this->byPublicId[$saved->publicId()->toString()] = $saved;

        return $saved;
    }

    public function findByPublicId(ExpensePeriodPublicId $publicId): ?ExpensePeriod
    {
        return $this->byPublicId[$publicId->toString()] ?? null;
    }

    public function findById(int $id): ?ExpensePeriod
    {
        foreach ($this->byPublicId as $period) {
            if ($period->id() === $id) {
                return $period;
            }
        }

        return null;
    }

    public function findOpenByCountry(string $country): ?ExpensePeriod
    {
        $countryCode = strtoupper(trim($country));
        foreach ($this->byPublicId as $period) {
            if ($period->country() === $countryCode && $period->status()->isOpen()) {
                return $period;
            }
        }

        return null;
    }

    public function findLatestByCountry(string $country): ?ExpensePeriod
    {
        $countryCode = strtoupper(trim($country));
        $latest = null;
        foreach ($this->byPublicId as $period) {
            if ($period->country() !== $countryCode) {
                continue;
            }
            if ($latest === null || $period->periodEnd() > $latest->periodEnd()) {
                $latest = $period;
            }
        }

        return $latest;
    }

    public function listByCountry(?string $country = null): array
    {
        $out = [];
        $countryCode = $country !== null ? strtoupper(trim($country)) : null;
        foreach ($this->byPublicId as $period) {
            if ($countryCode !== null && $period->country() !== $countryCode) {
                continue;
            }
            $out[] = $period;
        }

        usort($out, static fn (ExpensePeriod $a, ExpensePeriod $b): int => $b->periodEnd() <=> $a->periodEnd());

        return $out;
    }

    private function nextRowVersion(): RowVersionToken
    {
        return RowVersionToken::fromBinary(pack('NN', 0, $this->rowSeq++));
    }
}
