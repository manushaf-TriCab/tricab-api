<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\DTO;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;

final class ProductListCriteriaTest extends TestCase
{
    public function test_allowlisted_sort_and_pagination(): void
    {
        $criteria = new ProductListCriteria(page: 2, pageSize: 10, sortBy: ProductListCriteria::SORT_NAME);
        self::assertSame('name', $criteria->sortBy);
        self::assertSame('desc', $criteria->normalizedSortDirection());

        $pagination = Pagination::fromTotals(2, 10, 25);
        self::assertSame(3, $pagination->totalPages);
    }

    public function test_rejects_arbitrary_sort_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProductListCriteria(sortBy: 'DROP TABLE');
    }
}
