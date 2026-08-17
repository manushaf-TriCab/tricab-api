<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\GoogleUserNotRegistered;
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Validates a Google ID token and resolves an existing TriCab user by verified email.
 * Does not auto-create Users — Google is identity-only.
 *
 * Links Google + email-otp ExternalIdentities so the shared session JWT pipeline
 * can resolve /me the same way as guest email OTP.
 */
final class AuthenticateWithGoogleService
{
    public function __construct(
        private readonly GoogleIdTokenValidator $googleIdTokenValidator,
        private readonly UserProvisioningRepository $users,
    ) {
    }

    public function execute(string $idToken): User
    {
        $verified = $this->googleIdTokenValidator->validate($idToken);

        if (!$verified->emailVerified) {
            throw GoogleIdTokenInvalid::emailNotVerified();
        }

        $emailAddress = EmailAddress::fromString($verified->emailAddress);
        $user = $this->users->findByEmail($emailAddress);
        if ($user === null) {
            throw GoogleUserNotRegistered::create();
        }

        $user->assertAuthenticationAllowed();

        $googleIdentity = GoogleExternalIdentityFactory::forSubject($verified->subjectId);
        if ($this->users->findByExternalIdentity($googleIdentity) === null) {
            $this->users->linkExternalIdentity($user, $googleIdentity);
        }

        // Session JWTs resolve via email-otp ExternalIdentity (same as guest OTP).
        $emailOtpIdentity = EmailOtpExternalIdentityFactory::forEmail($emailAddress);
        if ($this->users->findByExternalIdentity($emailOtpIdentity) === null) {
            $this->users->linkExternalIdentity($user, $emailOtpIdentity);
        }

        return $user;
    }
}
