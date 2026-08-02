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
    ): IssuedGuestAccessToken {
        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $user->publicId()->toString(),
            'jti' => $jwtId->toString(),
            'iat' => $issuedAtUtc->getTimestamp(),
            'nbf' => $issuedAtUtc->getTimestamp(),
            'exp' => $expiresAtUtc->getTimestamp(),
            'auth_provider' => self::AUTH_PROVIDER,
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
}
