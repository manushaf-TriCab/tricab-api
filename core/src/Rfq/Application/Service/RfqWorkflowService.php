<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Rfq\Application\DTO\RfqAuditEvent;
use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Contract\RfqPeopleDirectory;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Contract\RfqWorkflowStore;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\Service\WorkflowTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\WorkflowChange;

/**
 * The workflow panel (parity plan E6): claim, assign, workflow, archive,
 * restore, comment, the activity trail, and the owner picker.
 *
 * Authorisation is the policies': {@see RfqAccessPolicy} for what the actor may
 * do to this RFQ and {@see WorkflowTransitionPolicy} for which status. As in
 * {@see RfqReviewService}, an RFQ the actor may not see is `null` (a 404), the
 * same as one that does not exist.
 *
 * Every write takes the revision the browser holds and returns the fresh
 * summary. The revision is checked, under the store's lock, and **not
 * bumped** ({@see RfqWorkflowStore}).
 */
final class RfqWorkflowService
{
    public const MAX_REASON = 500;
    public const MAX_COMMENT = 2_000;
    public const ACTIVITY_LIMIT = 200;
    public const PEOPLE_LIMIT = 10;
    public const MAX_PEOPLE_QUERY = 100;

    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly RfqWorkflowStore $store,
        private readonly RfqPeopleDirectory $people,
    ) {
    }

    /**
     * @throws RfqActionForbidden  when the RFQ is already assigned, archived, or the actor may not claim
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable
     * @throws RfqStoreUnavailable
     */
    public function claim(RfqActor $actor, string $email, string $displayName, string $publicId, int $expectedRevision): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        if (!in_array(RfqAction::CLAIM, RfqAccessPolicy::allowedActions($actor, $record->ownership), true)) {
            throw new RfqActionForbidden('claim this RFQ');
        }
        $name = trim($displayName) !== '' ? trim($displayName) : null;
        $draft = $this->draftId($publicId);
        if ($draft === null || !$this->store->claimForActor($draft, $expectedRevision, $this->worker($actor, $email), $name)) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * @param string|null $assigneeId the person's identity PublicId; null unassigns
     *
     * @throws InvalidRfqValue when the id is not an active user
     */
    public function assign(RfqActor $actor, string $email, string $publicId, int $expectedRevision, ?string $assigneeId): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        if (!RfqAccessPolicy::isManager($actor)) {
            throw new RfqActionForbidden('assign this RFQ');
        }
        $person = null;
        if ($assigneeId !== null) {
            $person = $this->people->find(strtolower(trim($assigneeId)))
                ?? throw new InvalidRfqValue('The person to assign is not an active user. Pick them again.');
        }
        $draft = $this->draftId($publicId);
        if ($draft === null || !$this->store->assign(
            $draft,
            $expectedRevision,
            $person?->email,
            $record->assigneeDisplayName,
            $person?->name,
            $this->worker($actor, $email),
        )) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * @throws InvalidRfqValue             for a status, priority or reason outside the vocabulary
     * @throws RfqActionForbidden          when the actor may not change the workflow, or the priority
     * @throws RfqTransitionNotPermitted   when the policy refuses the move
     */
    public function updateWorkflow(RfqActor $actor, string $email, string $publicId, int $expectedRevision, string $status, string $priority, string $reason): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        $workflowActor = RfqAccessPolicy::workflowActor($actor, $record->ownership)
            ?? throw new RfqActionForbidden('change the workflow of this RFQ');
        $to = WorkflowStatus::fromString($status);
        $newPriority = Priority::fromString($priority);
        $reason = self::text($reason, 'reason', 0, self::MAX_REASON);

        $draft = $this->draftId($publicId);
        $decide = static function (WorkflowStatus $from, Priority $currentPriority, bool $archived) use ($workflowActor, $to, $newPriority, $reason): WorkflowChange {
            if (!$newPriority->equals($currentPriority) && !WorkflowTransitionPolicy::mayChangePriorityOrDueDate($workflowActor)) {
                throw new RfqActionForbidden('change the priority of this RFQ');
            }
            WorkflowTransitionPolicy::assertCanSet($workflowActor, $from, $to, $archived, $reason);

            return new WorkflowChange(
                // Unchanged status: leave the column, which may hold ready_to_sync or failed.
                $from->equals($to) ? null : $to->toString(),
                $newPriority,
                [
                    'from_status' => $from->toString(),
                    'to_status' => $to->toString(),
                    'from_priority' => $currentPriority->toString(),
                    'to_priority' => $newPriority->toString(),
                    'reason' => $reason,
                ],
            );
        };
        if ($draft === null || !$this->store->setWorkflow($draft, $expectedRevision, $decide, $this->worker($actor, $email))) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * @throws InvalidRfqValue     for a missing or over-long reason
     * @throws RfqDraftNotEditable when archived already, or a sync is running
     */
    public function archive(RfqActor $actor, string $email, string $publicId, int $expectedRevision, string $reason): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        if (!$actor->can(Module::PERMISSION_DRAFT_ARCHIVE)) {
            throw new RfqActionForbidden('archive this RFQ');
        }
        $reason = self::text($reason, 'reason', 1, self::MAX_REASON);
        $draft = $this->draftId($publicId);
        if ($draft === null || !$this->store->archive($draft, $expectedRevision, $reason, $this->worker($actor, $email))) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * @throws RfqDraftNotEditable when it is not archived
     */
    public function restore(RfqActor $actor, string $email, string $publicId, int $expectedRevision): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        if (!$actor->can(Module::PERMISSION_DRAFT_RESTORE)) {
            throw new RfqActionForbidden('restore this RFQ');
        }
        $draft = $this->draftId($publicId);
        if ($draft === null || !$this->store->restore($draft, $expectedRevision, $this->worker($actor, $email))) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * An internal comment, on an archived RFQ too.
     *
     * @throws InvalidRfqValue for an empty or over-long message
     */
    public function comment(RfqActor $actor, string $email, string $publicId, int $expectedRevision, string $message): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);
        if ($record === null) {
            return null;
        }
        if (!in_array(RfqAction::COMMENT, RfqAccessPolicy::allowedActions($actor, $record->ownership), true)) {
            throw new RfqActionForbidden('comment on this RFQ');
        }
        $message = self::text($message, 'message', 1, self::MAX_COMMENT);
        $draft = $this->draftId($publicId);
        if ($draft === null || !$this->store->comment($draft, $expectedRevision, $message, $this->worker($actor, $email))) {
            return null;
        }

        return $this->fresh($actor, $publicId);
    }

    /**
     * @return list<RfqAuditEvent>|null newest first; null when there is no such RFQ for this actor
     */
    public function activity(RfqActor $actor, string $publicId): ?array
    {
        $record = $this->visible($actor, $publicId);

        return $record === null ? null : $this->rfqs->activity($record->publicId, self::ACTIVITY_LIMIT);
    }

    /**
     * The owner picker: managers only, as only they assign.
     *
     * @return list<RfqPerson>
     *
     * @throws RfqActionForbidden
     */
    public function people(RfqActor $actor, string $query): array
    {
        if (!RfqAccessPolicy::isManager($actor)) {
            throw new RfqActionForbidden('assign RFQs');
        }

        return $this->people->search(mb_substr(trim($query), 0, self::MAX_PEOPLE_QUERY), self::PEOPLE_LIMIT);
    }

    private function visible(RfqActor $actor, string $publicId): ?RfqRecord
    {
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));

        return $record === null || !RfqAccessPolicy::canSee($actor, $record->ownership) ? null : $record;
    }

    private function fresh(RfqActor $actor, string $publicId): ?RfqSummary
    {
        $record = $this->visible($actor, $publicId);

        return $record === null ? null : new RfqSummary($record, RfqAccessPolicy::allowedActions($actor, $record->ownership));
    }

    private function draftId(string $publicId): ?string
    {
        return StoreProjection::draftId(strtolower(trim($publicId)));
    }

    private function worker(RfqActor $actor, string $email): DraftActor
    {
        return new DraftActor($email, RfqAccessPolicy::isManager($actor) ? 'admin' : 'sales', $actor->branchCode);
    }

    private static function text(string $value, string $field, int $min, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) < $min) {
            throw new InvalidRfqValue(sprintf('The %s is required.', $field));
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidRfqValue(sprintf('The %s is longer than %s characters.', $field, number_format($max)));
        }

        return $value;
    }
}
