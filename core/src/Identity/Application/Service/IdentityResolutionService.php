<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Resolves an existing TriCab user from a verified provider identity.
 *
 * Provider authentication proves identity; TriCab owns authorization.
 * Never auto-creates users or assigns roles/permissions.
 */
final class IdentityResolutionService
{
    public function __construct(
        private readonly UserProvisioningRepository $users,
    ) {
    }

    /**
     * @throws UserNotRegistered
     * @throws IdentityConflict
     * @throws IdentityAmbiguous
     */
    public function resolve(ExternalIdentity $identity, ?EmailAddress $verifiedEmail): User
    {
        $linkedUser = $this->users->findByExternalIdentity($identity);
        if ($linkedUser !== null) {
            if ($verifiedEmail !== null && !$linkedUser->emailAddress()->equals($verifiedEmail)) {
                throw IdentityConflict::subjectLinkedToAnotherUser();
            }

            $linkedUser->assertAuthenticationAllowed();

            return $linkedUser;
        }

        if ($verifiedEmail === null) {
            throw UserNotRegistered::create();
        }

        $emailMatches = $this->users->findAllByEmail($verifiedEmail);
        if ($emailMatches === []) {
            throw UserNotRegistered::create();
        }

        if (count($emailMatches) > 1) {
            throw IdentityAmbiguous::duplicateEmail();
        }

        $emailUser = $emailMatches[0];
        $existingSubject = $this->users->findActiveProviderSubjectForUser(
            $emailUser->id(),
            $identity->provider(),
        );
        if ($existingSubject !== null && !$existingSubject->equals($identity->subjectId())) {
            throw IdentityConflict::providerSubjectMismatch();
        }

        $subjectOwner = $this->users->findByExternalIdentity($identity);
        if ($subjectOwner !== null && !$subjectOwner->id()->equals($emailUser->id())) {
            throw IdentityConflict::subjectLinkedToAnotherUser();
        }

        $this->users->linkExternalIdentity($emailUser, $identity);
        $emailUser->assertAuthenticationAllowed();

        return $emailUser;
    }
}
