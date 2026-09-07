<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;

/**
 * Dual-read ready attachment port. Stage 2 does not write Blob or download legacy files.
 */
interface ExpenseAttachmentStore
{
    public function describe(ExpenseAttachmentRef $ref): array;
}