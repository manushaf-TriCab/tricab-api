<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/** Builds the canonical ExternalIdentity for Apple Sign-In. */
final class AppleExternalIdentityFactory
{
    public const TENANT = 'appleid.apple.com';

    public static function forSubject(string $appleSubjectId): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::apple(),
            ExternalSubjectId::fromString($appleSubjectId),
            ExternalTenantId::fromString(self::TENANT),
        );
    }
}
