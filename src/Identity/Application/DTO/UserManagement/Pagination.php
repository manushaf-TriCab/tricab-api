<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }

    public static function fromTotals(int $page, int $pageSize, int $totalItems): self
    {
        $safePage = max(1, $page);
        $safePageSize = max(1, $pageSize);
        $safeTotal = max(0, $totalItems);
        $totalPages = $safeTotal === 0 ? 0 : (int) ceil($safeTotal / $safePageSize);

        return new self($safePage, $safePageSize, $safeTotal, $totalPages);
    }
}
