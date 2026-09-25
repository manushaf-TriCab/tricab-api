<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use DomainException;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;
use TriCab\Core\Onboarding\Domain\Exception\AssignmentStatusIsFinal;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\StatusChangeNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\ReviewDecisionPolicy;
use TriCab\Core\Onboarding\Domain\Service\SelfReviewPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * A reviewer's decision on one queued item (4.3).
 *
 * Three rules, applied in this order, each a refusal that is audited on its
 * own before it is rethrown:
 *
 *  1. **Not one's own** — {@see SelfReviewPolicy}. First, because it is true
 *     whatever the item's state, and it is the refusal that matters most.
 *  2. **The queue offers it** — {@see ReviewDecisionPolicy}.
 *  3. **A reviewer may set it** — {@see StatusTransition::attempt()}, which
 *     applies {@see \TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy}.
 *     Rule 2 is narrower, so today this never fires; it stays because it is
 *     the invariant and rule 2 is an endpoint's choice that may widen.
 *
 * On the WHSE induction ({@see \TriCab\Core\Onboarding\Domain\Service\TrainingReviewPolicy})
 * accepting needs a note as well — the supervisor's feedback — and afterwards
 * the assessment sheet is made again with it, HR's name and the date (5.4).
 *
 * A note missing where one is required is a malformed request (422) and is not
 * audited: it is the page's validation failing, not a decision refused.
 *
 * The write then carries two checks the plan could not: the row version, which
 * covers a second reviewer, and the status, which covers the trainee
 * resubmitting in between. Either failing is a conflict and writes nothing.
 */
final readonly class DecideReviewService
{
    public const PERMISSION = 'ONBOARDING.REVIEW';

    public function __construct(
        private OnboardingReviewRepository $reviews,
        private OnboardingAdminAuditLog $audit,
        /** 5.4: a form with HR or manager parts cannot be completed while a required one is empty. */
        private ?\TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository $contributions = null,
        /** 5.4: a form whose paper copy is made when it is accepted. */
        private ?GenerateFormSubmissionDocumentService $documents = null,
        /** 5.4: the WHSE sheet, stamped with HR's sign-off when it is accepted. */
        private ?GenerateAssessmentDocumentService $assessmentSheets = null,
    ) {
    }

    public function decide(
        ActorContext $actor,
        string $assignmentPublicId,
        RowVersionToken $expectedRowVersion,
        AssignmentStatus $outcome,
        ?string $note,
    ): ReviewItemDetail {
        $publicId = strtolower(trim($assignmentPublicId));
        $item = $this->reviews->findReviewItem($publicId)
            ?? throw OnboardingAssignmentNotFound::forThisPerson();

        $note = ReviewDecisionPolicy::normalizeNote($note);

        try {
            SelfReviewPolicy::assertNotSelfReview(
                StatusActor::reviewer(),
                UserId::fromInt($actor->userId),
                UserId::fromInt($item->subjectUserId),
            );
            ReviewDecisionPolicy::assertAllowed($item->status, $outcome, $note, $item->isSignOff());
            $this->assertContributionsComplete($item, $outcome);
            $transition = StatusTransition::attempt($item->status, $outcome, StatusActor::reviewer(), $actor->userId, $note);
        } catch (SelfReviewNotPermitted | ReviewDecisionNotPermitted | StatusChangeNotPermitted | AssignmentStatusIsFinal $refusal) {
            $this->audit->append($actor, self::refusal($item, $outcome, $refusal));

            throw $refusal;
        }

        $this->reviews->applyDecision($item, $expectedRowVersion, $transition, self::decided($item, $outcome), $actor);

        if ($item->itemType === 'form' && $outcome->toString() === AssignmentStatus::COMPLETED) {
            $this->documents?->generateAtCompletion($item->assignmentPublicId);
        }
        if ($item->isSignOff() && $outcome->toString() === AssignmentStatus::COMPLETED && $note !== null) {
            $this->assessmentSheets?->generateSignedOff(
                $item->subjectUserId,
                $item->assignmentPublicId,
                $actor->userId,
                $note,
                // The day in Melbourne, not the UTC date: a sign-off before
                // 10 or 11 am would otherwise print yesterday.
                (new \DateTimeImmutable('now', new \DateTimeZone(\TriCab\Core\Onboarding\Domain\Service\LeaverPolicy::LOCAL_TIMEZONE)))->format('Y-m-d'),
            );
        }

        return $this->reviews->reviewDetail($publicId)
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }

    private function assertContributionsComplete(ReviewItemRecord $item, AssignmentStatus $outcome): void
    {
        if ($this->contributions === null || $item->itemType !== 'form' || $outcome->toString() !== AssignmentStatus::COMPLETED) {
            return;
        }
        $form = $this->contributions->findContext($item->assignmentPublicId);
        if ($form === null) {
            return;
        }
        $missing = ContributeToFormService::missingContributions($form->schema, $this->contributions->latest($form->assignmentId));
        if ($missing !== []) {
            throw ReviewDecisionNotPermitted::contributionsMissing($missing);
        }
    }

    private static function decided(ReviewItemRecord $item, AssignmentStatus $outcome): OnboardingAdminEvent
    {
        $verb = match ($outcome->toString()) {
            AssignmentStatus::COMPLETED => 'Accepted',
            AssignmentStatus::FIX_AND_RESUBMIT => 'Sent back for changes',
            default => 'Replied to a request for help on',
        };

        return OnboardingAdminEvent::succeeded(
            AdminEventType::REVIEW_DECIDED,
            $item->subjectUserId,
            $item->assignmentPublicId,
            sprintf('%s %s "%s".', $verb, $item->itemType, $item->title),
            self::details($item, $outcome),
            self::PERMISSION,
        );
    }

    private static function refusal(ReviewItemRecord $item, AssignmentStatus $outcome, DomainException $refusal): OnboardingAdminEvent
    {
        $reason = match (true) {
            $refusal instanceof SelfReviewNotPermitted => 'self_review',
            $refusal instanceof ReviewDecisionNotPermitted => $refusal->reason,
            $refusal instanceof AssignmentStatusIsFinal => 'final',
            default => 'status_not_settable',
        };

        return OnboardingAdminEvent::refused(
            AdminEventType::REVIEW_REFUSED,
            $item->subjectUserId,
            $item->assignmentPublicId,
            sprintf('Review of %s "%s" refused: %s', $item->itemType, $item->title, $refusal->getMessage()),
            [...self::details($item, $outcome), 'reason' => $reason],
            self::PERMISSION,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function details(ReviewItemRecord $item, AssignmentStatus $outcome): array
    {
        return [
            'itemType' => $item->itemType,
            'itemPublicId' => $item->itemPublicId,
            'fromStatus' => $item->status->toString(),
            'toStatus' => $outcome->toString(),
            'revisionNumber' => $item->latestRevisionNumber,
        ];
    }
}
