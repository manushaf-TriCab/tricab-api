<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityNotLinked;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Resolves a platform user from an already-verified external identity.
 * JWT / Entra token validation belongs in the API adapter, not here.
 */
final class ResolveAuthenticatedUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @throws IdentityNotLinked
     * @throws AuthenticationNotAllowed
     */
    public function handle(VerifiedIdentity $verifiedIdentity): AuthenticatedUser
    {
        $externalIdentity = new ExternalIdentity(
            ExternalProvider::fromString($verifiedIdentity->provider),
            ExternalSubjectId::fromString($verifiedIdentity->subjectId),
            ExternalTenantId::fromString($verifiedIdentity->tenantId),
        );

        $user = $this->users->findByExternalIdentity($externalIdentity);

        if ($user === null) {
            throw IdentityNotLinked::forExternalIdentity(
                $verifiedIdentity->provider,
                $verifiedIdentity->subjectId,
                $verifiedIdentity->tenantId,
            );
        }

        $user->assertAuthenticationAllowed();

        return AuthenticatedUser::fromUser($user);
    }
}
