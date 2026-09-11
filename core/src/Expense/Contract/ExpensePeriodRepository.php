<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

interface ExpensePeriodRepository
{
    public function save(ExpensePeriod $period, ?RowVersionToken $expectedRowVersion = null): ExpensePeriod;

    public function findByPublicId(ExpensePeriodPublicId $publicId): ?ExpensePeriod;

    public function findById(int $id): ?ExpensePeriod;

    public function findOpenByCountry(string $country): ?ExpensePeriod;

    /** Latest closed/open period for country by period_end desc. */
    public function findLatestByCountry(string $country): ?ExpensePeriod;

    /** @return list<ExpensePeriod> */
    public function listByCountry(?string $country = null): array;
}
