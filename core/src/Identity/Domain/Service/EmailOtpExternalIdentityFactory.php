<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Builds the canonical ExternalIdentity for guest email-otp logins.
 */
final class EmailOtpExternalIdentityFactory
{
    public const TENANT = 'guest';

    public static function forEmail(EmailAddress $emailAddress): ExternalIdentity
    {
        return new ExternalIdentity(
            ExternalProvider::emailOtp(),
            ExternalSubjectId::fromString($emailAddress->toString()),
            ExternalTenantId::fromString(self::TENANT),
        );
    }
}
