<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\PasskeyAssertionResult;
use TriCab\Core\Identity\Application\DTO\PasskeyAttestationResult;
use TriCab\Core\Identity\Application\DTO\PasskeyPublicKeyOptions;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;

/**
 * WebAuthn ceremony port. Cryptography lives in the infrastructure adapter.
 */
interface PasskeyCeremony
{
    /**
     * @param list<string> $excludeCredentialIds
     */
    public function registrationOptions(
        PasskeyRpConfig $rp,
        string $userHandleBinary,
        string $userName,
        string $displayName,
        array $excludeCredentialIds,
    ): PasskeyPublicKeyOptions;

    public function completeRegistration(
        PasskeyRpConfig $rp,
        string $expectedChallengeBinary,
        string $userHandleBinary,
        string $clientOrigin,
        string $attestationJson,
    ): PasskeyAttestationResult;

    public function authenticationOptions(PasskeyRpConfig $rp): PasskeyPublicKeyOptions;

    public function completeAuthentication(
        PasskeyRpConfig $rp,
        string $expectedChallengeBinary,
        string $credentialId,
        string $publicKey,
        int $storedSignCount,
        string $clientOrigin,
        string $assertionJson,
    ): PasskeyAssertionResult;
}
