<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use Closure;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\WorkflowChange;

/**
 * The workflow panel's writes (parity plan E6): claim, assign, workflow,
 * archive, restore, comment.
 *
 * Each is one transaction with its activity row, and each locks the draft row
 * and checks the revision first, as a content save does. **None bumps the
 * revision**: the revision counts saved content, which these do not touch, so
 * a rep mid-edit is not told to reload because a manager changed a priority.
 * They do check it, so a write based on a stale view is refused with
 * {@see RfqRevisionConflict} and nothing is written.
 *
 * Every method returns false when there is no such draft. Who may do what is
 * the policies'; what the store re-checks, under the lock, is the state a
 * race could have changed (archived, already assigned, sync running).
 */
interface RfqWorkflowStore
{
    /**
     * Assign the draft to the actor, if nobody has it.
     *
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable archived, or somebody claimed it first
     */
    public function claimForActor(string $draftId, int $expectedRevision, DraftActor $actor, ?string $actorName): bool;

    /**
     * Set or clear the assignee. Names are for the activity; the trail holds no emails.
     *
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable archived
     */
    public function assign(string $draftId, int $expectedRevision, ?string $assigneeEmail, ?string $fromName, ?string $toName, DraftActor $actor): bool;

    /**
     * `$decide` is given the status, priority and archived flag the store holds
     * under its lock and returns the change to make, or throws what the rules
     * refuse with.
     *
     * @param Closure(WorkflowStatus, Priority, bool): WorkflowChange $decide
     *
     * @throws RfqRevisionConflict
     */
    public function setWorkflow(string $draftId, int $expectedRevision, Closure $decide, DraftActor $actor): bool;

    /**
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable already archived, or a sync is running
     */
    public function archive(string $draftId, int $expectedRevision, string $reason, DraftActor $actor): bool;

    /**
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable not archived
     */
    public function restore(string $draftId, int $expectedRevision, DraftActor $actor): bool;

    /**
     * An internal comment. Allowed on an archived RFQ.
     *
     * @throws RfqRevisionConflict
     */
    public function comment(string $draftId, int $expectedRevision, string $message, DraftActor $actor): bool;
}
