<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Service\ProviderBindingPolicy;
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
            // Any of the user's verified addresses, not just the primary one.
            // Comparing against the primary address alone meant a person with
            // both a personal and a corporate address could use exactly one of
            // them: changing the primary to fix one login broke the other.
            if ($verifiedEmail !== null
                && !$this->users->hasVerifiedAddress($linkedUser->id(), $verifiedEmail)
            ) {
                throw IdentityConflict::subjectLinkedToAnotherUser();
            }

            $linkedUser->assertAuthenticationAllowed();

            return $linkedUser;
        }

        if ($verifiedEmail === null) {
            throw UserNotRegistered::create();
        }

        $emailMatches = $this->users->findAllByVerifiedEmail($verifiedEmail);
        if ($emailMatches === []) {
            throw UserNotRegistered::create();
        }

        if (count($emailMatches) > 1) {
            throw IdentityAmbiguous::duplicateEmail();
        }

        $match = $emailMatches[0];

        // An address admits only the providers its purpose allows. Without
        // this, adding someone's personal address would let anyone who can
        // authenticate to Google as that address auto-link to their platform
        // user and inherit every role they hold.
        //
        // Refused as UserNotRegistered, not as its own error: a distinct code
        // would confirm to a stranger that the address belongs to somebody
        // here, which is the enumeration the guest OTP flow already avoids.
        if ($match->purpose !== null
            && !ProviderBindingPolicy::allows($identity->provider(), $match->purpose)
        ) {
            throw UserNotRegistered::create();
        }

        $emailUser = $match->user;
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
