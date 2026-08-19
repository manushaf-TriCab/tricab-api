<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\AppleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/** Validates Apple Sign-In and resolves an existing TriCab user. */
final class AuthenticateWithAppleService
{
    public function __construct(
        private readonly AppleAuthorizationCodeExchanger $authorizationCodeExchanger,
        private readonly AppleIdTokenValidator $appleIdTokenValidator,
        private readonly IdentityResolutionService $identityResolution,
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
        $appleIdentity = AppleExternalIdentityFactory::forSubject($verified->subjectId);

        return $this->identityResolution->resolve($appleIdentity, $emailAddress);
    }
}
