<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;

final class PasskeyExternalIdentityFactory
{
    public const TENANT = 'webauthn';

    public static function forHandle(WebAuthnUserHandle $handle): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::passkey(),
            ExternalSubjectId::fromString($handle->toHex()),
            ExternalTenantId::fromString(self::TENANT),
        );
    }
}
