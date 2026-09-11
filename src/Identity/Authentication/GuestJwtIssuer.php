<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use TriCab\Core\Identity\Application\DTO\IssuedGuestAccessToken;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Issues HS256 guest access tokens. Never logs token contents.
 */
final class GuestJwtIssuer implements GuestAccessTokenIssuer
{
    public const AUTH_PROVIDER = 'email-otp';
    public const ALGORITHM = 'HS256';

    /** Guest JWT auth_provider values issued by TriCab (identity only; not authorization). */
    public const ALLOWED_AUTH_PROVIDERS = [
        self::AUTH_PROVIDER,
        'google',
        'apple',
        'passkey',
        'trusted-device',
    ];

    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $secret,
    ) {
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('GUEST_JWT_SECRET must be at least 32 bytes.');
        }
    }

    public function issue(
        User $user,
        UuidPublicId $sessionPublicId,
        UuidPublicId $jwtId,
        DateTimeImmutable $issuedAtUtc,
        DateTimeImmutable $expiresAtUtc,
        string $authProvider = self::AUTH_PROVIDER,
    ): IssuedGuestAccessToken {
        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $user->publicId()->toString(),
            'jti' => $jwtId->toString(),
            'iat' => $issuedAtUtc->getTimestamp(),
            'nbf' => $issuedAtUtc->getTimestamp(),
            'exp' => $expiresAtUtc->getTimestamp(),
            'auth_provider' => self::normalizeAuthProvider($authProvider),
            'session_id' => $sessionPublicId->toString(),
            'email' => $user->emailAddress()->toString(),
        ];

        $token = JWT::encode($payload, $this->secret, self::ALGORITHM);

        return new IssuedGuestAccessToken(
            token: $token,
            expiresAtUtc: $expiresAtUtc,
            sessionPublicId: $sessionPublicId->toString(),
            jwtId: $jwtId->toString(),
        );
    }

    public static function normalizeAuthProvider(string $authProvider): string
    {
        $normalized = strtolower(trim($authProvider));
        if ($normalized === '') {
            return self::AUTH_PROVIDER;
        }
        if (!in_array($normalized, self::ALLOWED_AUTH_PROVIDERS, true)) {
            return self::AUTH_PROVIDER;
        }

        return $normalized;
    }

    public static function isAllowedAuthProvider(string $authProvider): bool
    {
        return in_array(strtolower(trim($authProvider)), self::ALLOWED_AUTH_PROVIDERS, true);
    }
}
