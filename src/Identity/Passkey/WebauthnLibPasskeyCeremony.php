<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Passkey;

use Cose\Algorithms;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;
use TriCab\Core\Identity\Application\DTO\PasskeyAssertionResult;
use TriCab\Core\Identity\Application\DTO\PasskeyAttestationResult;
use TriCab\Core\Identity\Application\DTO\PasskeyPublicKeyOptions;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Production WebAuthn adapter using web-auth/webauthn-lib.
 *
 * Chosen because it is the maintained PHP FIDO2 implementation, supports PHP 8.3,
 * and performs attestation/assertion cryptography rather than ad-hoc COSE code.
 */
final class WebauthnLibPasskeyCeremony implements PasskeyCeremony
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
        $challenge = random_bytes(32);
        $exclude = [];
        foreach ($excludeCredentialIds as $credentialId) {
            $binary = self::decodeCredentialId($credentialId);
            if ($binary !== '') {
                $exclude[] = PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $binary,
                );
            }
        }

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($rp->rpName, $rp->rpId),
            user: PublicKeyCredentialUserEntity::create($userName, $userHandleBinary, $displayName),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: 60000,
        );

        return new PasskeyPublicKeyOptions($challenge, $this->toArray($options));
    }

    public function completeRegistration(
        PasskeyRpConfig $rp,
        string $expectedChallengeBinary,
        string $userHandleBinary,
        string $clientOrigin,
        string $attestationJson,
    ): PasskeyAttestationResult {
        $this->assertOrigin($rp, $clientOrigin);
        $host = self::originHost($clientOrigin);
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($rp->rpName, $rp->rpId),
            user: PublicKeyCredentialUserEntity::create('user', $userHandleBinary, 'user'),
            challenge: $expectedChallengeBinary,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );

        try {
            $credential = $this->parseCredential($attestationJson);
            $response = $credential->response;
            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw PasskeyAuthFailed::signatureInvalid();
            }
            $source = $this->attestationValidator()->check($response, $options, $host);
        } catch (PasskeyAuthFailed $exception) {
            throw $exception;
        } catch (Throwable) {
            throw PasskeyAuthFailed::signatureInvalid();
        }

        if (!$response->attestationObject->authData->isUserVerified()) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }

        return new PasskeyAttestationResult(
            credentialId: self::base64Url($source->publicKeyCredentialId),
            publicKey: $this->serializeRecord($source),
            signCount: $source->counter,
            transports: array_values($source->transports),
            aaguid: $source->aaguid->toRfc4122(),
            userVerified: true,
        );
    }

    public function authenticationOptions(PasskeyRpConfig $rp): PasskeyPublicKeyOptions
    {
        $challenge = random_bytes(32);
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rp->rpId,
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 60000,
        );

        return new PasskeyPublicKeyOptions($challenge, $this->toArray($options));
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
        $this->assertOrigin($rp, $clientOrigin);
        $host = self::originHost($clientOrigin);
        $source = $this->deserializeRecord($publicKey, $credentialId, $storedSignCount);
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $expectedChallengeBinary,
            rpId: $rp->rpId,
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        try {
            $credential = $this->parseCredential($assertionJson);
            $response = $credential->response;
            if (!$response instanceof AuthenticatorAssertionResponse) {
                throw PasskeyAuthFailed::signatureInvalid();
            }
            $updated = $this->assertionValidator()->check(
                $source,
                $response,
                $options,
                $host,
                $source->userHandle,
            );
        } catch (PasskeyAuthFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'counter')) {
                throw PasskeyAuthFailed::clonedAuthenticator();
            }
            throw PasskeyAuthFailed::signatureInvalid();
        }

        $signCount = $updated->counter;
        if (!$response->authenticatorData->isUserVerified()) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }

        return new PasskeyAssertionResult(
            credentialId: $credentialId,
            signCount: $signCount,
            userVerified: true,
            userHandleHex: $updated->userHandle === '' ? null : bin2hex($updated->userHandle),
        );
    }

    private function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setCounterChecker(new SyncedPasskeyCounterChecker());

        return AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
    }

    private function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setCounterChecker(new SyncedPasskeyCounterChecker());

        return AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
    }

    private function parseCredential(string $json): PublicKeyCredential
    {
        $serializer = (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
        try {
            return $serializer->deserialize($json, PublicKeyCredential::class, JsonEncoder::FORMAT);
        } catch (Throwable) {
            throw PasskeyAuthFailed::signatureInvalid();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $options): array
    {
        $serializer = (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
        $json = $serializer->serialize($options, JsonEncoder::FORMAT);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw PasskeyAuthFailed::notEnabled();
        }

        $asMap = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $asMap[$key] = $value;
            }
        }

        return $asMap;
    }

    private function serializeRecord(CredentialRecord $source): string
    {
        return json_encode([
            'publicKeyCredentialId' => self::base64Url($source->publicKeyCredentialId),
            'credentialPublicKey' => base64_encode($source->credentialPublicKey),
            'userHandle' => $source->userHandle === '' ? null : base64_encode($source->userHandle),
            'counter' => $source->counter,
            'aaguid' => $source->aaguid->toRfc4122(),
            'transports' => $source->transports,
            'type' => $source->type,
            'attestationType' => $source->attestationType,
        ], JSON_THROW_ON_ERROR);
    }

    private function deserializeRecord(string $publicKey, string $credentialId, int $storedSignCount): CredentialRecord
    {
        $decoded = json_decode($publicKey, true);
        if (!is_array($decoded)) {
            throw PasskeyAuthFailed::unknownCredential();
        }
        $record = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $record[$key] = $value;
            }
        }
        $pkRaw = $record['credentialPublicKey'] ?? null;
        $idRaw = $record['publicKeyCredentialId'] ?? $credentialId;
        $pk = is_string($pkRaw) ? base64_decode($pkRaw, true) : false;
        $id = is_string($idRaw) ? self::decodeCredentialId($idRaw) : '';
        $handleRaw = $record['userHandle'] ?? null;
        $handle = is_string($handleRaw) ? base64_decode($handleRaw, true) : '';
        if ($pk === false || $id === '') {
            throw PasskeyAuthFailed::unknownCredential();
        }

        $transports = [];
        if (isset($record['transports']) && is_array($record['transports'])) {
            foreach ($record['transports'] as $transport) {
                if (is_string($transport)) {
                    $transports[] = $transport;
                }
            }
        }

        $aaguid = isset($record['aaguid']) && is_string($record['aaguid'])
            ? $record['aaguid']
            : '00000000-0000-0000-0000-000000000000';
        $attestationType = isset($record['attestationType']) && is_string($record['attestationType'])
            ? $record['attestationType']
            : 'none';

        return CredentialRecord::create(
            publicKeyCredentialId: $id,
            type: 'public-key',
            transports: $transports,
            attestationType: $attestationType,
            trustPath: EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString($aaguid),
            credentialPublicKey: $pk,
            userHandle: is_string($handle) ? $handle : '',
            counter: $storedSignCount,
        );
    }

    private function assertOrigin(PasskeyRpConfig $rp, string $clientOrigin): void
    {
        if (!$rp->allowsOrigin($clientOrigin)) {
            throw PasskeyAuthFailed::wrongOrigin();
        }
    }

    private static function originHost(string $origin): string
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw PasskeyAuthFailed::wrongOrigin();
        }

        return $host;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decodeCredentialId(string $credentialId): string
    {
        $padded = strtr($credentialId, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad !== 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
