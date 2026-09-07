<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;

final class MetadataExpenseAttachmentStore implements ExpenseAttachmentStore
{
    public function describe(ExpenseAttachmentRef $ref): array
    {
        return [
            'storageSource' => $ref->storageSource(),
            'fileName' => $ref->fileName(),
            'hasReference' => $ref->reference() !== '',
        ];
    }
}