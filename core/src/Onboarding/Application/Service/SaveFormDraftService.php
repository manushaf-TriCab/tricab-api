<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Keeps somebody's half-finished form.
 *
 * ## The one place in Phase 3.3 where a row version is the right tool
 *
 * Presentation progress only grows, so a race loses nothing. An acknowledgement
 * happens once, so there is nothing to race. A draft is neither: two tabs with
 * the same form open will overwrite each other, and what is lost is twenty
 * minutes of somebody's typing that they will not notice is gone until they
 * submit.
 *
 * So a save carries the token it read, and a stale one is refused. The first
 * save has no token because there is no row yet — null means insert, and the
 * insert races on `UQ_onboarding_FormDrafts_Assignment`, which is the same
 * answer arriving by a different route.
 *
 * Phase 2.5's trap applies directly and the client has to know it: **an UPDATE
 * that changes nothing still moves the token.** A Save-on-a-timer that posts
 * every field whether or not the person typed invalidates the other tab's token
 * once a minute, for no reason. Save on change.
 *
 * ## A draft is not split
 *
 * Unlike a submission. It is unfinished typing, it is deleted the moment the
 * form is handed in, and classifying fields that may still change would mean
 * refusing to save a payload whose shape is not final yet — which is the same as
 * having no draft. `onboarding.FormDrafts` sits inside the module's trust
 * boundary and is read by nothing outside it.
 */
final readonly class SaveFormDraftService
{
    public function __construct(
        private OnboardingFormRepository $forms,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $draftData
     *
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws OnboardingWriteConflict
     */
    public function save(
        int $userId,
        string $assignmentPublicId,
        string $versionPublicId,
        array $draftData,
        ?RowVersionToken $expectedRowVersion,
    ): OnboardingFormDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->forms->findForm($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        // Asked here rather than only in SQL so that the answer is the same
        // whichever adapter is running: a client that has a draft and sends no
        // token is trying to blind-overwrite, and an insert that then failed on
        // the unique constraint would report the wrong reason.
        if ($detail->hasDraft() && $expectedRowVersion === null) {
            throw OnboardingWriteConflict::staleToken();
        }

        return $this->forms->saveDraft(
            $userId,
            $assignmentPublicId,
            $versionPublicId,
            $draftData,
            $expectedRowVersion,
            self::openingTransition($detail->status, $userId),
        );
    }

    private static function openingTransition(AssignmentStatus $from, int $userId): ?StatusTransition
    {
        if ($from->toString() !== AssignmentStatus::NOT_STARTED) {
            return null;
        }

        return StatusTransition::traineeMovesTo(
            $from,
            AssignmentStatus::inProgress(),
            $userId,
            'Started filling in the form.',
        );
    }
}
