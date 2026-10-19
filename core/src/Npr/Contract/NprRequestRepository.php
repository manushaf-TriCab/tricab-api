<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprRequest;

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
}
