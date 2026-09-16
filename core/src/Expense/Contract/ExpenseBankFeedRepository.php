<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

interface ExpenseBankFeedRepository
{
    public function save(ExpenseBankFeedEntry $entry, ?RowVersionToken $expectedRowVersion = null): ExpenseBankFeedEntry;

    public function findByPublicId(ExpenseBankFeedPublicId $publicId): ?ExpenseBankFeedEntry;

    public function findById(int $id): ?ExpenseBankFeedEntry;

    public function findByBankReference(string $bankReference): ?ExpenseBankFeedEntry;

    /** @return list<ExpenseBankFeedEntry> */
    public function listUnmatchedByCountry(string $country): array;

    /** @return list<ExpenseBankFeedEntry> */
    public function listByPeriodId(int $periodId): array;

    /** @return list<ExpenseBankFeedEntry> */
    public function listByCountry(string $country): array;

    /** @return list<ExpenseBankFeedEntry> */
    public function listEligibleForOffset(string $country): array;

    /** @return list<ExpenseBankFeedEntry> */
    public function listAll(): array;

    public function minTransactionDate(string $country): ?DateTimeImmutable;

    public function maxStatementDate(string $country): ?DateTimeImmutable;
}
