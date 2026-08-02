<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;

/**
 * Maps a validated guest token to the shared VerifiedIdentity used by /me.
 */
final class GuestVerifiedIdentityFactory
{
    public static function fromValidatedToken(ValidatedGuestToken $token): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: ExternalProvider::EMAIL_OTP,
            subjectId: $token->emailAddress,
            tenantId: EmailOtpExternalIdentityFactory::TENANT,
        );
    }
}
