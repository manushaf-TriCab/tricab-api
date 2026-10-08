<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Attachments;

use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/** The behaviour in {@see FinanceAttachmentStoreBehaviour}, against the in-memory twin. */
final class InMemoryFinanceAttachmentStoreTest extends FinanceAttachmentStoreBehaviour
{
    private InMemoryFinanceAttachmentStore $memory;

    protected function newStore(): FinanceAttachmentStore
    {
        return $this->memory = new InMemoryFinanceAttachmentStore();
    }

    protected function blobCount(): int
    {
        return count($this->memory->blobPaths());
    }
}
