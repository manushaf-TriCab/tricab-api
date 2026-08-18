<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;
use TriCab\Core\Identity\Application\Exception\AppleUserNotRegistered;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Validates an Apple authorization code flow and resolves an existing TriCab user by verified email.
 *
 * Does not auto-create Users. Does not link Apple or email-otp ExternalIdentities.
 * Session JWT /me resolution for Apple relies on GuestJwtValidator loading the user by session UserId
 * and attaching AuthenticatedUser — not on ExternalIdentity rows.
 */
final class AuthenticateWithAppleService
{
    public function __construct(
        private readonly AppleAuthorizationCodeExchanger $authorizationCodeExchanger,
        private readonly AppleIdTokenValidator $appleIdTokenValidator,
        private readonly UserRepository $users,
    ) {
    }

    public function execute(string $authorizationCode, string $expectedNonce): User
    {
        $idToken = $this->authorizationCodeExchanger->exchange($authorizationCode);
        if (trim($idToken) === '') {
            throw AppleTokenExchangeFailed::create('Apple token response did not include an ID token.');
        }

        $verified = $this->appleIdTokenValidator->validate($idToken, $expectedNonce);

        if (!$verified->emailVerified) {
            throw AppleIdTokenInvalid::emailNotVerified();
        }

        $emailAddress = EmailAddress::fromString($verified->emailAddress);
        $user = $this->users->findByEmail($emailAddress);
        if ($user === null) {
            throw AppleUserNotRegistered::create();
        }

        $user->assertAuthenticationAllowed();

        return $user;
    }
}
