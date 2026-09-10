<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\DTO;

use TriCab\Core\Customer\Application\DTO\Pagination;

final readonly class LogNoteListResult
{
    /**
     * @param list<LogNoteSummary> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }
}
