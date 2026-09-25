<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;

/**
 * The read side of one person's own onboarding.
 *
 * Every method takes a `$userId` and every implementation must filter on it in
 * the query rather than fetching and filtering afterwards. That is not a
 * performance note: a filter applied after the fact is one an exception, an
 * early return or a later refactor can skip, and the failure mode is handing
 * somebody another person's induction.
 *
 * There is deliberately no `findAssignmentByPublicId($publicId)` without a user
 * id. An id-only lookup is the shape that invites a caller to check ownership
 * itself, and one caller eventually will not.
 */
interface OnboardingSelfServiceRepository
{
    /** Null when this person has no induction record, which is the common case. */
    public function findTraineeRecord(int $userId): ?OnboardingTraineeRecord;

    /**
     * This person's live allocations, withdrawn ones excluded.
     *
     * A withdrawal is evidence-preserving — the row stays, with everything
     * attempted against it — but it is no longer on the person's plate, and
     * showing it would ask them to finish work HR has taken back.
     *
     * @return list<OnboardingAssignmentView>
     */
    public function listAssignments(int $userId): array;
}
