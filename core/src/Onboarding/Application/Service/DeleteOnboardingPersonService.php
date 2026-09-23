<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\Exception\PersonDeletionNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * HR deletes an induction record that should not exist — added by mistake,
 * added twice, a test.
 *
 * ## Only while nothing is recorded against it
 *
 * Progress, attempts, acknowledgements, drafts, submissions and documents are
 * evidence (HR-held details come only from a submission); several sit behind triggers that refuse DELETE.
 * A person who has done anything is refused, and HR records a last day instead
 * — the source deleted leavers and lost every record with them. Allocations
 * nobody started are removed with the record; they are HR's own choices, not
 * the person's work.
 *
 * ## The account is disabled, not deleted
 *
 * `audit.UserManagementEvents` and `audit.OnboardingAdminEvents` both name the
 * account, both are append-only, and a deleted account would leave their rows
 * pointing at nobody. So the account stays, and is **disabled** — through User
 * Management's own audited status change — only when onboarding created it and
 * it still holds nothing but `GUEST`
 * ({@see OnboardingAccountDirectory::isOnboardingOnlyAccount()}). An employee
 * or customer linked to a record keeps their account exactly as it was.
 *
 * Record, allocations and status change are one unit: a failure part-way
 * leaves the record in place and the account as it was.
 */
final readonly class DeleteOnboardingPersonService
{
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';

    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingAccountDirectory $accounts,
        private OnboardingAdminAuditLog $audit,
        private ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @return array{accountDisabled: bool}
     *
     * @throws OnboardingPersonNotFound
     * @throws PersonDeletionNotPermitted
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function delete(ActorContext $actor, string $personPublicId, RowVersionToken $expectedRowVersion): array
    {
        $publicId = strtolower(trim($personPublicId));
        $person = $this->people->findPerson($publicId) ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        $work = $this->people->recordedWork($person->userId);
        if ($work !== []) {
            $this->refuse($actor, $person->userId, $publicId, PersonDeletionNotPermitted::hasRecordedWork($work));
        }

        $disable = $this->accounts->isOnboardingOnlyAccount($person->userId);

        try {
            $this->transaction->run(function () use ($actor, $person, $publicId, $expectedRowVersion, $disable): void {
                $this->people->deletePerson($person, $expectedRowVersion, OnboardingAdminEvent::succeeded(
                    AdminEventType::PERSON_DELETED,
                    $person->userId,
                    $publicId,
                    $disable
                        ? 'Deleted an induction record nothing was recorded against, and disabled the GUEST account made for it.'
                        : 'Deleted an induction record nothing was recorded against. The account was not made by onboarding and is unchanged.',
                    ['accountDisabled' => $disable],
                    self::PERMISSION,
                ), $actor);

                if ($disable) {
                    $this->accounts->disableAccount($actor, $person->userId);
                }
            });
        } catch (PersonDeletionNotPermitted $refused) {
            $this->refuse($actor, $person->userId, $publicId, $refused);
        }

        return ['accountDisabled' => $disable];
    }

    /**
     * @throws PersonDeletionNotPermitted always
     */
    private function refuse(ActorContext $actor, int $userId, string $publicId, PersonDeletionNotPermitted $refusal): never
    {
        $this->audit->append($actor, OnboardingAdminEvent::refused(
            AdminEventType::PERSON_DELETED,
            $userId,
            $publicId,
            'Induction record not deleted: work is recorded against it.',
            ['reason' => 'has_recorded_work'] + $refusal->recordedWork,
            self::PERMISSION,
        ));

        throw $refusal;
    }
}
