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
use Throwable;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;
use TriCab\Core\Identity\Contract\GuestAccessTokenValidator;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\GuestSessionExpired;
use TriCab\Core\Identity\Domain\Exception\GuestSessionRevoked;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;
use UnexpectedValueException;

/**
 * Validates guest JWTs cryptographically, then binds them to a persisted GuestSession.
 */
final class GuestJwtValidator implements GuestAccessTokenValidator
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $secret,
        private readonly int $ttlSeconds,
        private readonly int $clockSkewSeconds,
        private readonly GuestSessionRepository $sessions,
        private readonly SessionTokenHasher $tokenHasher,
        private readonly UserRepository $users,
        private readonly Clock $clock = new SystemClock(),
    ) {
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('GUEST_JWT_SECRET must be at least 32 bytes.');
        }
    }

    public function validate(string $accessToken): ValidatedGuestToken
    {
        $claims = $this->decodeAndVerifyClaims($accessToken);
        $this->assertRequiredClaims($claims);
        $this->assertTtlWithinLimit($claims);

        $sessionPublicId = UuidPublicId::fromString(self::stringClaim($claims, 'session_id'));
        $jwtId = UuidPublicId::fromString(self::stringClaim($claims, 'jti'));
        $userPublicId = UserPublicId::fromString(self::stringClaim($claims, 'sub'));
        $email = self::stringClaim($claims, 'email');
        $exp = self::intClaim($claims, 'exp');

        $tokenHash = $this->tokenHasher->hash($accessToken);
        $session = $this->sessions->findBySessionTokenHash($tokenHash);
        if ($session === null) {
            throw AuthTokenValidationFailed::sessionNotFound();
        }

        if (!$session->publicId()->equals($sessionPublicId)) {
            throw AuthTokenValidationFailed::invalid('Session identifier mismatch.');
        }

        $sessionJwtId = $session->jwtId();
        if ($sessionJwtId === null || !$sessionJwtId->equals($jwtId)) {
            throw AuthTokenValidationFailed::invalid('JWT ID mismatch.');
        }

        $now = $this->clock->now();
        try {
            $session->assertActive($now);
        } catch (GuestSessionRevoked) {
            throw AuthTokenValidationFailed::sessionRevoked();
        } catch (GuestSessionExpired) {
            throw AuthTokenValidationFailed::expired('Guest session has expired.');
        }

        $sessionExp = $session->expiresAtUtc()->getTimestamp();
        if ($exp > $sessionExp + $this->clockSkewSeconds) {
            throw AuthTokenValidationFailed::invalid('Token expiration exceeds session lifetime.');
        }

        $user = $this->users->findById($session->userId());
        if ($user === null) {
            throw AuthTokenValidationFailed::sessionNotFound();
        }

        if (!$user->publicId()->equals($userPublicId)) {
            throw AuthTokenValidationFailed::invalid('Subject mismatch.');
        }

        if ($user->emailAddress()->toString() !== strtolower(trim($email))) {
            throw AuthTokenValidationFailed::invalid('Email claim mismatch.');
        }

        try {
            $user->assertAuthenticationAllowed();
        } catch (AuthenticationNotAllowed) {
            throw AuthTokenValidationFailed::userInactive();
        }

        return new ValidatedGuestToken(
            userPublicId: $user->publicId()->toString(),
            guestSessionPublicId: $session->publicId()->toString(),
            jwtId: $jwtId->toString(),
            expiresAtUtc: (new DateTimeImmutable('@' . $exp))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            emailAddress: $user->emailAddress()->toString(),
            // Session-backed TriCab JWTs resolve the user by GuestSession.UserId.
            // Attach AuthenticatedUser so /me and RequirePermission work without ExternalIdentity
            // (Apple Sign-In does not persist provider links).
            authenticatedUser: AuthenticatedUser::fromUser($user),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndVerifyClaims(string $accessToken): array
    {
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->clockSkewSeconds;
        // Align JWT exp/nbf checks with the injected clock (tests + deterministic validation).
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $decoded = JWT::decode($accessToken, new Key($this->secret, GuestJwtIssuer::ALGORITHM));
        } catch (ExpiredException) {
            throw AuthTokenValidationFailed::expired();
        } catch (BeforeValidException) {
            throw AuthTokenValidationFailed::invalid('Token is not yet valid.');
        } catch (SignatureInvalidException) {
            throw AuthTokenValidationFailed::invalid('Token signature is invalid.');
        } catch (UnexpectedValueException) {
            throw AuthTokenValidationFailed::invalid('Token is malformed or uses an unsupported algorithm.');
        } catch (Throwable) {
            throw AuthTokenValidationFailed::invalid();
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        /** @var array<string, mixed> $claims */
        $claims = (array) $decoded;

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertRequiredClaims(array $claims): void
    {
        foreach (['iss', 'aud', 'sub', 'jti', 'iat', 'nbf', 'exp', 'auth_provider', 'session_id', 'email'] as $claim) {
            if (!array_key_exists($claim, $claims) || $claims[$claim] === null || $claims[$claim] === '') {
                throw AuthTokenValidationFailed::invalid('Missing required claim.');
            }
        }

        if (self::stringClaim($claims, 'iss') !== $this->issuer) {
            throw AuthTokenValidationFailed::invalid('Unexpected token issuer.');
        }

        $audience = $claims['aud'];
        $audienceOk = is_string($audience)
            ? $audience === $this->audience
            : (is_array($audience) && in_array($this->audience, $audience, true));
        if (!$audienceOk) {
            throw AuthTokenValidationFailed::invalid('Unexpected token audience.');
        }

        if (self::stringClaim($claims, 'auth_provider') !== GuestJwtIssuer::AUTH_PROVIDER) {
            throw AuthTokenValidationFailed::invalid('Unexpected authentication provider.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertTtlWithinLimit(array $claims): void
    {
        $iat = self::intClaim($claims, 'iat');
        $exp = self::intClaim($claims, 'exp');
        if ($exp <= $iat) {
            throw AuthTokenValidationFailed::invalid('Token lifetime is invalid.');
        }

        if (($exp - $iat) > ($this->ttlSeconds + $this->clockSkewSeconds)) {
            throw AuthTokenValidationFailed::invalid('Token lifetime exceeds configured maximum.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function stringClaim(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw AuthTokenValidationFailed::invalid('Missing required claim.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function intClaim(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw AuthTokenValidationFailed::invalid('Missing required claim.');
        }

        return (int) $value;
    }
}
