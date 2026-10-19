<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotDeletable;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprTransition;

/**
 * Storage for NPR requests and their revisions. Callers decide who may see or
 * change a record; this only stores it.
 */
interface NprRequestRepository
{
    /** Opens a draft at revision 1 with the next request number, and records the activity. */
    public function create(NewNprRequest $draft): NprRequest;

    public function find(string $publicId): ?NprRequest;

    /**
     * Hard-deletes a draft that was never submitted, with every row that hangs
     * off it (notification deliveries and outbox, endorsements, attachment rows,
     * activity, revisions), in one transaction. Returns false when it does not exist.
     *
     * @throws NprRequestNotDeletable when it is not a draft, or was ever submitted
     */
    public function deleteNeverSubmitted(string $publicId): bool;

    /**
     * Newest first.
     *
     * @return list<NprRequest>
     */
    public function listForRequester(int $userId): array;

    /**
     * Requests with TSD or later (`RequestStatus::tsdVisible()`), newest first.
     *
     * @return list<NprRequest>
     */
    public function listForTsd(): array;

    /**
     * Saves answers on a request Sales can still edit. A draft's current
     * revision is rewritten in place; a request returned to Sales gets a new
     * immutable revision (the source's rule, so a signed revision is never
     * overwritten). Returns null when the request does not exist.
     *
     * @throws NprRequestConflict    when `$expectedVersion` is not the stored version
     * @throws NprRequestNotEditable when the status no longer allows Sales to edit
     */
    public function saveDraft(string $publicId, string $expectedVersion, NprDraftChange $change): ?NprRequest;

    /**
     * Requests assigned to this manager for endorsement or since (never a draft), newest first.
     *
     * @return list<NprRequest>
     */
    public function listForManager(int $userId): array;

    /**
     * Applies one workflow step: re-reads the request under a lock, refuses a
     * stale `$expectedVersion` and a status outside `$transition->from`, then
     * writes the header, the answer patch, any endorsement row and exactly one
     * Activity row together. Returns null when the request does not exist.
     *
     * @throws NprRequestConflict when `$expectedVersion` is not the stored version
     * @throws NprWorkflowRefused when the status is not one the step may start from
     */
    public function transition(string $publicId, string $expectedVersion, NprTransition $transition): ?NprRequest;
}
