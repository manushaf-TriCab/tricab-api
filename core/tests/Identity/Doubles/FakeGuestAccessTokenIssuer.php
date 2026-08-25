<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\IssuedGuestAccessToken;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Deterministic guest token issuer for Core unit tests (no JWT library).
 */
final class FakeGuestAccessTokenIssuer implements GuestAccessTokenIssuer
{
    public function issue(
        User $user,
        UuidPublicId $sessionPublicId,
        UuidPublicId $jwtId,
        DateTimeImmutable $issuedAtUtc,
        DateTimeImmutable $expiresAtUtc,
        string $authProvider = 'email-otp',
    ): IssuedGuestAccessToken {
        $token = implode('.', [
            'header',
            base64_encode(json_encode([
                'iss' => 'https://api.tricab.local',
                'aud' => 'tricab-api',
                'sub' => $user->publicId()->toString(),
                'jti' => $jwtId->toString(),
                'session_id' => $sessionPublicId->toString(),
                'email' => $user->emailAddress()->toString(),
                'auth_provider' => $authProvider,
                'iat' => $issuedAtUtc->getTimestamp(),
                'exp' => $expiresAtUtc->getTimestamp(),
            ], JSON_THROW_ON_ERROR) ?: ''),
            'signature',
        ]);

        return new IssuedGuestAccessToken(
            token: $token,
            expiresAtUtc: $expiresAtUtc,
            sessionPublicId: $sessionPublicId->toString(),
            jwtId: $jwtId->toString(),
        );
    }
}
