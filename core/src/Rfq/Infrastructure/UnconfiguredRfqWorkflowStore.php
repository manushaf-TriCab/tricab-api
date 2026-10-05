<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use Closure;
use TriCab\Core\Rfq\Contract\RfqWorkflowStore;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Storage\DraftActor;

/** Where there is no Azure SQL: every workflow write refuses as unavailable. */
final class UnconfiguredRfqWorkflowStore implements RfqWorkflowStore
{
    public function claimForActor(string $draftId, int $expectedRevision, DraftActor $actor, ?string $actorName): bool
    {
        throw new RfqStoreUnavailable();
    }

    public function assign(string $draftId, int $expectedRevision, ?string $assigneeEmail, ?string $fromName, ?string $toName, DraftActor $actor): bool
    {
        throw new RfqStoreUnavailable();
    }

    public function setWorkflow(string $draftId, int $expectedRevision, Closure $decide, DraftActor $actor): bool
    {
        throw new RfqStoreUnavailable();
    }

    public function archive(string $draftId, int $expectedRevision, string $reason, DraftActor $actor): bool
    {
        throw new RfqStoreUnavailable();
    }

    public function restore(string $draftId, int $expectedRevision, DraftActor $actor): bool
    {
        throw new RfqStoreUnavailable();
    }

    public function comment(string $draftId, int $expectedRevision, string $message, DraftActor $actor): bool
    {
        throw new RfqStoreUnavailable();
    }
}
