<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Resolves a platform user from an already-verified external identity.
 * JWT / provider token validation belongs in the API adapter, not here.
 */
final class ResolveAuthenticatedUserHandler
{
    public function __construct(
        private readonly IdentityResolutionService $identityResolution,
    ) {
    }

    /**
     * @throws UserNotRegistered
     * @throws IdentityConflict
     * @throws IdentityAmbiguous
     * @throws AuthenticationNotAllowed
     * @throws InvalidIdentityValue
     */
    public function handle(VerifiedIdentity $verifiedIdentity): AuthenticatedUser
    {
        $externalIdentity = new ExternalIdentity(
            ExternalProvider::fromString($verifiedIdentity->provider),
            ExternalSubjectId::fromString($verifiedIdentity->subjectId),
            ExternalTenantId::fromString($verifiedIdentity->tenantId),
        );

        $verifiedEmail = trim((string) ($verifiedIdentity->verifiedEmail ?? ''));
        $emailAddress = $verifiedEmail !== ''
            ? EmailAddress::fromString($verifiedEmail)
            : null;

        $user = $this->identityResolution->resolve($externalIdentity, $emailAddress);

        return AuthenticatedUser::fromUser($user);
    }
}
