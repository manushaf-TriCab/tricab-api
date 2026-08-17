<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use DateTimeImmutable;
use DateTimeZone;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Identity\Application\Exception\AppleAuthorizationChallengeInvalid;
use UnexpectedValueException;

/**
 * Issues and validates short-lived Apple Sign-In challenge state JWTs (HS256).
 * State binds provider=apple and a SHA-256 hash of the nonce using GUEST_JWT_SECRET.
 */
final class AppleAuthorizationChallengeService
{
    public const PROVIDER = 'apple';
    public const TTL_SECONDS = 300;
    public const ALGORITHM = 'HS256';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array{state: string, nonce: string, expiresAtUtc: string}
     */
    public function issue(): array
    {
        if (!$this->settings->isAppleAuthConfigured()) {
            throw AppleAuthorizationChallengeInvalid::create('Apple Sign-In is not configured.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . self::TTL_SECONDS . ' seconds');
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $jti = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        $state = JWT::encode(
            [
                'iss' => $this->settings->guestJwtIssuer,
                'aud' => $this->settings->guestJwtAudience,
                'provider' => self::PROVIDER,
                'nonce_hash' => hash('sha256', $nonce),
                'jti' => $jti,
                'iat' => $now->getTimestamp(),
                'nbf' => $now->getTimestamp(),
                'exp' => $expiresAt->getTimestamp(),
            ],
            $this->settings->guestJwtSecret,
            self::ALGORITHM,
        );

        return [
            'state' => $state,
            'nonce' => $nonce,
            'expiresAtUtc' => $expiresAt->format(DATE_ATOM),
        ];
    }

    public function validate(string $state, string $nonce): void
    {
        if (!$this->settings->isAppleAuthConfigured()) {
            throw AppleAuthorizationChallengeInvalid::create('Apple Sign-In is not configured.');
        }

        $token = trim($state);
        $expectedNonce = trim($nonce);
        if ($token === '' || $expectedNonce === '' || substr_count($token, '.') !== 2) {
            throw AppleAuthorizationChallengeInvalid::create();
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->settings->guestJwtClockSkewSeconds;

        try {
            $decoded = JWT::decode($token, new Key($this->settings->guestJwtSecret, self::ALGORITHM));
        } catch (ExpiredException) {
            throw AppleAuthorizationChallengeInvalid::expired();
        } catch (BeforeValidException | SignatureInvalidException | UnexpectedValueException) {
            throw AppleAuthorizationChallengeInvalid::create();
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $claims = (array) $decoded;
        if (($claims['iss'] ?? null) !== $this->settings->guestJwtIssuer) {
            throw AppleAuthorizationChallengeInvalid::create();
        }

        $audience = $claims['aud'] ?? null;
        $audienceOk = is_string($audience)
            ? $audience === $this->settings->guestJwtAudience
            : (is_array($audience) && in_array($this->settings->guestJwtAudience, $audience, true));
        if (!$audienceOk) {
            throw AppleAuthorizationChallengeInvalid::create();
        }

        if (($claims['provider'] ?? null) !== self::PROVIDER) {
            throw AppleAuthorizationChallengeInvalid::wrongProvider();
        }

        $nonceHash = $claims['nonce_hash'] ?? null;
        if (!is_string($nonceHash) || !hash_equals($nonceHash, hash('sha256', $expectedNonce))) {
            throw AppleAuthorizationChallengeInvalid::nonceMismatch();
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || trim($jti) === '') {
            throw AppleAuthorizationChallengeInvalid::create();
        }
    }
}
