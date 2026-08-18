<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Builds the canonical ExternalIdentity for Google Sign-In.
 */
final class GoogleExternalIdentityFactory
{
    public const TENANT = 'accounts.google.com';

    public static function forSubject(string $googleSubjectId): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::google(),
            ExternalSubjectId::fromString($googleSubjectId),
            ExternalTenantId::fromString(self::TENANT),
        );
    }
}
