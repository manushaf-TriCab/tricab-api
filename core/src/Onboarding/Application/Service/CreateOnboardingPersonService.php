<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * HR adds a person to onboarding (work order 4.1).
 *
 * ## Linked, or created — never changed
 *
 * The email address decides. If a platform account already holds it — an
 * employee moving roles, somebody imported from the legacy staff list — the
 * induction record is linked to that account and **nothing about the account
 * changes**: not its status, its roles or its name. A pending account stays
 * pending, and the response says it cannot sign in yet, because activating
 * somebody is a platform access decision that belongs in User Management.
 *
 * If no account holds it, one is created holding only `GUEST`, with the address
 * recorded as personal (owner decision, 23 Sep 2026). See
 * {@see OnboardingAccountDirectory::createGuestAccount()}.
 *
 * ## One unit
 *
 * The account, its User Management audit row, the induction record and its
 * Onboarding audit row are written in one {@see ApplicationTransaction}. A
 * failure part-way leaves no account without the record it was made for.
 *
 * ## No code, no invitation
 *
 * Creating a person sends nothing. Inviting is a separate, deliberate action
 * ({@see InviteOnboardingPersonService}), so HR can set a person up weeks
 * before they are meant to hear from TriCab.
 */
final readonly class CreateOnboardingPersonService
{
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';

    public function __construct(
        private OnboardingAccountDirectory $accounts,
        private OnboardingPeopleRepository $people,
        private OnboardingAdminAuditLog $audit,
        private ApplicationTransaction $transaction,
        private UuidV4Generator $ids,
    ) {
    }

    /**
     * @return array{detail: OnboardingPersonDetail, accountCreated: bool}
     */
    public function create(ActorContext $actor, string $emailAddress, string $displayName, OnboardingProfileFields $fields): array
    {
        try {
            $email = EmailAddress::fromString(trim($emailAddress));
        } catch (InvalidIdentityValue) {
            throw InvalidOnboardingValue::for('emailAddress', '(not an email address)');
        }
        $name = trim($displayName);
        if (mb_strlen($name) > 200) {
            throw InvalidOnboardingValue::for('displayName', '(longer than 200 characters)');
        }

        $publicId = $this->ids->generate()->toString();

        try {
            $accountCreated = $this->transaction->run(function () use ($actor, $email, $name, $fields, $publicId): bool {
                $userId = $this->accounts->findUserIdByEmail($email);
                $created = false;

                if ($userId === null) {
                    // Only a new account needs a name from HR; an existing one keeps its own.
                    if ($name === '') {
                        throw InvalidOnboardingValue::required('displayName', 'a new starter needs a name for their account.');
                    }
                    $userId = $this->accounts->createGuestAccount($actor, $email, $name);
                    $created = true;
                } elseif (($existing = $this->people->findPersonPublicIdByUserId($userId)) !== null) {
                    throw OnboardingPersonAlreadyExists::forPerson($existing);
                }

                $this->people->createProfile($userId, $publicId, $fields, OnboardingAdminEvent::succeeded(
                    AdminEventType::PERSON_CREATED,
                    $userId,
                    $publicId,
                    $created
                        ? 'Created an induction record and a new GUEST account for it.'
                        : 'Created an induction record for an existing account.',
                    ['accountCreated' => $created],
                    self::PERMISSION,
                ), $actor);

                return $created;
            });
        } catch (OnboardingPersonAlreadyExists $exists) {
            // Refused, and on record. The subject is known here, so it is named.
            $this->audit->append($actor, OnboardingAdminEvent::refused(
                AdminEventType::PERSON_CREATED,
                $this->accounts->findUserIdByEmail($email),
                $exists->personPublicId,
                'Induction record not created: one already exists.',
                ['reason' => 'already_exists'],
                self::PERMISSION,
            ));

            throw $exists;
        }

        $detail = $this->people->personDetail($publicId) ?? throw OnboardingPersonNotFound::forPublicId($publicId);
        $record = $this->people->findPerson($publicId) ?? throw OnboardingPersonNotFound::forPublicId($publicId);

        return [
            'detail' => $detail->withCanSignIn($this->accounts->canSignIn($record->userId)),
            'accountCreated' => $accountCreated,
        ];
    }
}
