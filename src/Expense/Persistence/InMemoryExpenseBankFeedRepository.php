<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryExpenseBankFeedRepository implements ExpenseBankFeedRepository
{
    /** @var array<string, ExpenseBankFeedEntry> */
    private array $byPublicId = [];

    private int $nextId = 1;

    private int $rowSeq = 1;

    public function save(ExpenseBankFeedEntry $entry, ?RowVersionToken $expectedRowVersion = null): ExpenseBankFeedEntry
    {
        $key = $entry->publicId()->toString();
        $existing = $this->byPublicId[$key] ?? null;

        if ($expectedRowVersion !== null) {
            $current = $existing?->rowVersion();
            if ($current === null || !$current->equals($expectedRowVersion)) {
                throw ExpenseConcurrencyConflict::forBankFeed();
            }
        }

        $saved = $entry->id() === null ? $entry->withId($this->nextId++) : $entry;
        $saved = $saved->withRowVersion($this->nextRowVersion());
        $this->byPublicId[$saved->publicId()->toString()] = $saved;

        return $saved;
    }

    public function findByPublicId(ExpenseBankFeedPublicId $publicId): ?ExpenseBankFeedEntry
    {
        return $this->byPublicId[$publicId->toString()] ?? null;
    }

    public function findById(int $id): ?ExpenseBankFeedEntry
    {
        foreach ($this->byPublicId as $entry) {
            if ($entry->id() === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function findByBankReference(string $bankReference): ?ExpenseBankFeedEntry
    {
        $needle = trim($bankReference);
        foreach ($this->byPublicId as $entry) {
            if ($entry->bankReference() === $needle) {
                return $entry;
            }
        }

        return null;
    }

    public function listUnmatchedByCountry(string $country): array
    {
        $countryCode = strtoupper(trim($country));
        $out = [];
        foreach ($this->byPublicId as $entry) {
            if ($entry->country() !== $countryCode) {
                continue;
            }
            if (!$entry->matchStatus()->isUnmatched()) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    public function listByPeriodId(int $periodId): array
    {
        $out = [];
        foreach ($this->byPublicId as $entry) {
            if ($entry->periodId() === $periodId) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function listByCountry(string $country): array
    {
        $countryCode = strtoupper(trim($country));
        $out = [];
        foreach ($this->byPublicId as $entry) {
            if ($entry->country() === $countryCode) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function listEligibleForOffset(string $country): array
    {
        $out = [];
        foreach ($this->listByCountry($country) as $entry) {
            if ($entry->isEligibleForOffset()) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function listAll(): array
    {
        return array_values($this->byPublicId);
    }

    public function minTransactionDate(string $country): ?DateTimeImmutable
    {
        $min = null;
        foreach ($this->listByCountry($country) as $entry) {
            $tx = $entry->transactionDate();
            if ($tx === null) {
                continue;
            }
            if ($min === null || $tx < $min) {
                $min = $tx;
            }
        }

        return $min;
    }

    public function maxStatementDate(string $country): ?DateTimeImmutable
    {
        $max = null;
        foreach ($this->listByCountry($country) as $entry) {
            $stmt = $entry->statementDate() ?? $entry->transactionDate();
            if ($stmt === null) {
                continue;
            }
            if ($max === null || $stmt > $max) {
                $max = $stmt;
            }
        }

        return $max;
    }

    private function nextRowVersion(): RowVersionToken
    {
        return RowVersionToken::fromBinary(pack('NN', 0, $this->rowSeq++));
    }
}
