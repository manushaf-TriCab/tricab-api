<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\SplitFormPayload;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * One person's allocated forms: the draft they are typing and the revisions they
 * have handed in.
 *
 * ## Two lifetimes, two tables, two rules
 *
 * A **draft** is scratch. It is mutable, it is overwritten in place, and it is
 * the one thing in Phase 3.3 that two tabs can genuinely destroy — which is why
 * {@see saveDraft()} takes a {@see RowVersionToken} and refuses a stale one.
 *
 * A **submission** is evidence. `TR_FormSubmissions_Immutable` refuses an UPDATE
 * or a DELETE at the database, and a correction is revision 2 rather than an
 * edit. {@see submitForm()} therefore takes no token: there is no version of the
 * previous revision to be stale against.
 *
 * ## Nothing unsplit crosses this boundary
 *
 * {@see submitForm()} takes a {@see SplitFormPayload} and not an array. The
 * splitter has to have run, and the ordinary half goes to
 * `onboarding.FormSubmissions` while the payroll and restricted halves go to
 * `[onboarding_hr]` — never the other way, never both. A `SELECT *` over the
 * workflow schema must not be able to return a bank account, and the way that is
 * guaranteed is that the bank account was never written there.
 *
 * A draft is exempt, and deliberately: it is the person's own unfinished typing,
 * it is deleted when the form is handed in, and splitting a half-filled payload
 * would mean classifying fields that may still change. `onboarding.FormDrafts`
 * is inside the module's trust boundary and is not read by reports.
 */
interface OnboardingFormRepository
{
    /**
     * Null when the assignment is not this person's, is not a form, or has been
     * withdrawn — indistinguishable on purpose (invariant 4).
     */
    public function findForm(int $userId, string $assignmentPublicId): ?OnboardingFormDetail;

    /**
     * The per-field classification map for a form version, as
     * `[onboarding_hr].[FieldClassifications]` holds it.
     *
     * Leaf path to {@see \TriCab\Core\Onboarding\Domain\ValueObject\DataClass}
     * value. An empty map is a form nobody has classified, and
     * {@see \TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter} refuses
     * every field in it — which is the fail-closed behaviour, not a gap.
     *
     * @return array<string, int>
     */
    public function fieldClassifications(string $formVersionPublicId): array;

    /**
     * Saves the person's own draft.
     *
     * `$expectedRowVersion` is null for the first save, when there is no row to
     * be stale against, and required afterwards. A stale token raises
     * {@see \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict::staleToken()}
     * — following Phase 2.5: `UPDATE … WHERE [RowVersion] = :expected` with an
     * empty `OUTPUT INSERTED.*` result IS the conflict, not `execute()` plus a
     * row count, which {@see \TriCab\Api\Infrastructure\Database\SqlConnection}
     * does not offer.
     *
     * Note the trap Phase 2.5 recorded and this method inherits: an UPDATE that
     * changes nothing still moves the row's token. A Save button posting every
     * field on a timer therefore invalidates every other editor's token even
     * when the person typed nothing, so a client should save on change rather
     * than on a clock.
     *
     * @param array<string, mixed> $draftData
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     */
    public function saveDraft(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $draftData,
        ?RowVersionToken $expectedRowVersion,
        ?StatusTransition $transition,
    ): OnboardingFormDetail;

    /**
     * Writes a submission revision, clears the draft, and moves the item on.
     *
     * The status goes to `submitted` rather than `completed`: a form is checked
     * by a person, and invariant 7 means the person who filled it in is not that
     * person. Phase 4's reviewer queue is what moves it the rest of the way.
     *
     * The revision number is allocated by the implementation, inside the same
     * transaction, from `UQ_onboarding_FormSubmissions_Revision`. A caller that
     * chose it would be racing every other caller for the same assignment.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     */
    public function submitForm(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        SplitFormPayload $payload,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
    ): OnboardingFormDetail;
}
