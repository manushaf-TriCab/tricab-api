<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoredDraft;

/**
 * The draft store where there is no Azure SQL (`USER_REPOSITORY=memory`):
 * every write refuses as unavailable, and a read finds nothing, matching the
 * empty in-memory queue. A write that pretended to succeed here would be the
 * scaffold's localStorage "save" again (plan 1.6).
 */
final class UnconfiguredRfqDraftStore implements RfqDraftStore
{
    public function create(NewDraft $draft): string
    {
        throw new RfqStoreUnavailable();
    }

    public function saveContent(
        string $draftId,
        int $expectedRevision,
        array $quoteDetails,
        array $rows,
        string $nextStatus,
        DraftActor $actor,
        string $viewMode = '',
    ): ?int {
        throw new RfqStoreUnavailable();
    }

    public function load(string $draftId): ?StoredDraft
    {
        return null;
    }
}
