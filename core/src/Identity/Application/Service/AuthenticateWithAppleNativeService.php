<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Contract\AppleNativeIdTokenValidator;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\AppleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Validates a native Sign in with Apple identity token and resolves an existing TriCab user.
 * Additive to the web Services ID authorization-code flow.
 */
final class AuthenticateWithAppleNativeService
{
    public function __construct(
        private readonly AppleNativeIdTokenValidator $appleNativeIdTokenValidator,
        private readonly IdentityResolutionService $identityResolution,
    ) {
    }

    public function execute(string $identityToken): User
    {
        $verified = $this->appleNativeIdTokenValidator->validateNative($identityToken);
        $appleIdentity = AppleExternalIdentityFactory::forSubject($verified->subjectId);

        $email = strtolower(trim($verified->emailAddress));
        $verifiedEmail = null;
        if ($email !== '') {
            if (!$verified->emailVerified) {
                throw AppleIdTokenInvalid::emailNotVerified();
            }
            $verifiedEmail = EmailAddress::fromString($email);
        }

        // Subject-first: subsequent Apple logins often omit email; linked ExternalIdentity is authoritative.
        return $this->identityResolution->resolve($appleIdentity, $verifiedEmail);
    }
}
