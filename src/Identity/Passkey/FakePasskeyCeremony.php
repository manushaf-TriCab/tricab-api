<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Passkey;

use TriCab\Core\Identity\Application\DTO\PasskeyAssertionResult;
use TriCab\Core\Identity\Application\DTO\PasskeyAttestationResult;
use TriCab\Core\Identity\Application\DTO\PasskeyPublicKeyOptions;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\PasskeyCeremony;

/**
 * Deterministic WebAuthn stand-in for HTTP tests. Production uses WebauthnLibPasskeyCeremony.
 */
final class FakePasskeyCeremony implements PasskeyCeremony
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
    ): PasskeyPublicKeyOptions {
        unset($excludeCredentialIds);
        $challenge = random_bytes(32);

        return new PasskeyPublicKeyOptions($challenge, [
            'rp' => ['name' => $rp->rpName, 'id' => $rp->rpId],
            'user' => [
                'id' => rtrim(strtr(base64_encode($userHandleBinary), '+/', '-_'), '='),
                'name' => $userName,
                'displayName' => $displayName,
            ],
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'userVerification' => 'required',
                'requireResidentKey' => true,
            ],
            'attestation' => 'none',
            'timeout' => 60000,
        ]);
    }

    public function completeRegistration(
        PasskeyRpConfig $rp,
        string $expectedChallengeBinary,
        string $userHandleBinary,
        string $clientOrigin,
        string $attestationJson,
    ): PasskeyAttestationResult {
        unset($userHandleBinary);
        $payload = $this->decode($attestationJson);
        $this->assertChallenge($expectedChallengeBinary, $payload);
        $this->assertRpAndOrigin($rp, $clientOrigin, $payload);
        if (($payload['userVerification'] ?? true) !== true) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }
        $credentialId = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        if ($credentialId === '') {
            throw PasskeyAuthFailed::signatureInvalid();
        }

        $publicKey = isset($payload['publicKey']) && is_string($payload['publicKey']) ? $payload['publicKey'] : 'test-public-key';
        $transports = [];
        if (isset($payload['transports']) && is_array($payload['transports'])) {
            foreach ($payload['transports'] as $transport) {
                if (is_string($transport)) {
                    $transports[] = $transport;
                }
            }
        }

        return new PasskeyAttestationResult(
            credentialId: $credentialId,
            publicKey: $publicKey,
            signCount: is_int($payload['signCount'] ?? null) ? $payload['signCount'] : 0,
            transports: $transports,
            aaguid: isset($payload['aaguid']) && is_string($payload['aaguid']) ? $payload['aaguid'] : null,
            userVerified: true,
        );
    }

    public function authenticationOptions(PasskeyRpConfig $rp): PasskeyPublicKeyOptions
    {
        $challenge = random_bytes(32);

        return new PasskeyPublicKeyOptions($challenge, [
            'rpId' => $rp->rpId,
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'userVerification' => 'required',
            'timeout' => 60000,
            'allowCredentials' => [],
        ]);
    }

    public function completeAuthentication(
        PasskeyRpConfig $rp,
        string $expectedChallengeBinary,
        string $credentialId,
        string $publicKey,
        int $storedSignCount,
        string $clientOrigin,
        string $assertionJson,
    ): PasskeyAssertionResult {
        unset($publicKey);
        $payload = $this->decode($assertionJson);
        $this->assertChallenge($expectedChallengeBinary, $payload);
        $this->assertRpAndOrigin($rp, $clientOrigin, $payload);
        if (($payload['userVerification'] ?? true) !== true) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }
        if (($payload['signature'] ?? '') !== 'valid') {
            throw PasskeyAuthFailed::signatureInvalid();
        }
        $incomingId = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        if ($incomingId === '' || !hash_equals($credentialId, $incomingId)) {
            throw PasskeyAuthFailed::unknownCredential();
        }
        $signCount = is_int($payload['signCount'] ?? null) ? $payload['signCount'] : 0;
        if ($storedSignCount > 0 && $signCount > 0 && $signCount < $storedSignCount) {
            throw PasskeyAuthFailed::clonedAuthenticator();
        }

        return new PasskeyAssertionResult(
            credentialId: $incomingId,
            signCount: $signCount,
            userVerified: true,
            userHandleHex: isset($payload['userHandle']) && is_string($payload['userHandle']) ? $payload['userHandle'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertChallenge(string $expectedChallengeBinary, array $payload): void
    {
        if (!array_key_exists('challenge', $payload)) {
            return;
        }
        $expected = rtrim(strtr(base64_encode($expectedChallengeBinary), '+/', '-_'), '=');
        $actual = isset($payload['challenge']) && is_string($payload['challenge']) ? $payload['challenge'] : '';
        if (!hash_equals($expected, $actual)) {
            throw PasskeyAuthFailed::challengeInvalid();
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw PasskeyAuthFailed::signatureInvalid();
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertRpAndOrigin(PasskeyRpConfig $rp, string $clientOrigin, array $payload): void
    {
        $origin = isset($payload['origin']) && is_string($payload['origin']) ? $payload['origin'] : $clientOrigin;
        if (!$rp->allowsOrigin($origin) || !$rp->allowsOrigin($clientOrigin)) {
            throw PasskeyAuthFailed::wrongOrigin();
        }
        $rpId = isset($payload['rpId']) && is_string($payload['rpId']) ? $payload['rpId'] : $rp->rpId;
        if ($rpId !== $rp->rpId) {
            throw PasskeyAuthFailed::wrongRpId();
        }
    }
}
