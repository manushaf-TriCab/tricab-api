<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Authentication;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\AuthTokenValidationFailed;
use TriCab\Api\Identity\Authentication\CompositeBearerTokenValidator;
use TriCab\Api\Identity\Authentication\EntraTokenValidationFailed;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\GuestJwtIssuer;
use TriCab\Api\Identity\Authentication\GuestJwtValidator;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Doubles\InMemoryGuestSessionRepository;
use TriCab\Api\Tests\Doubles\StubUserRepository;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\Sha256SessionTokenHasher;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class GuestJwtAuthenticationTest extends TestCase
{
    private const SECRET = 'unit-test-guest-jwt-secret-key-32b-minimum!!';
    private const ISSUER = 'https://api.tricab.local';
    private const AUDIENCE = 'tricab-api';

    public function test_issuer_creates_expected_claims_and_24h_expiry(): void
    {
        $issuedAt = new DateTimeImmutable('2026-07-31T00:00:00+00:00');
        $expiresAt = $issuedAt->add(new DateInterval('PT86400S'));
        $user = $this->guestUser();

        $issued = (new GuestJwtIssuer(self::ISSUER, self::AUDIENCE, self::SECRET))->issue(
            user: $user,
            sessionPublicId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            jwtId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440002'),
            issuedAtUtc: $issuedAt,
            expiresAtUtc: $expiresAt,
        );

        $parts = explode('.', $issued->token);
        self::assertCount(3, $parts);
        /** @var array<string, mixed> $claims */
        $claims = json_decode($this->b64($parts[1]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::ISSUER, $claims['iss']);
        self::assertSame(self::AUDIENCE, $claims['aud']);
        self::assertSame($user->publicId()->toString(), $claims['sub']);
        self::assertSame('550e8400-e29b-41d4-a716-446655440002', $claims['jti']);
        self::assertSame('550e8400-e29b-41d4-a716-446655440001', $claims['session_id']);
        self::assertSame('email-otp', $claims['auth_provider']);
        self::assertIsInt($claims['exp']);
        self::assertIsInt($claims['iat']);
        self::assertSame(86400, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('roles', $claims);
        self::assertArrayNotHasKey('permissions', $claims);
    }

    public function test_valid_token_validates_against_persisted_session(): void
    {
        [$token, $validator] = $this->issueAndBuildValidator();

        $validated = $validator->validate($token);
        self::assertSame('550e8400-e29b-41d4-a716-446655440099', $validated->userPublicId);
        self::assertSame('guest@example.com', $validated->emailAddress);
    }

    public function test_invalid_signature_rejected(): void
    {
        [$token, $validator] = $this->issueAndBuildValidator();
        $parts = explode('.', $token);
        $parts[2] = 'tampered-signature';

        $this->expectException(AuthTokenValidationFailed::class);
        $validator->validate(implode('.', $parts));
    }

    public function test_wrong_issuer_rejected(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = JWT::encode([
            'iss' => 'https://evil.example',
            'aud' => self::AUDIENCE,
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->add(new DateInterval('PT1H'))->getTimestamp(),
            'auth_provider' => 'email-otp',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_wrong_audience_rejected(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => 'other-api',
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->add(new DateInterval('PT1H'))->getTimestamp(),
            'auth_provider' => 'email-otp',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_wrong_auth_provider_rejected(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->add(new DateInterval('PT1H'))->getTimestamp(),
            'auth_provider' => 'microsoft-entra',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_expired_token_rejected(): void
    {
        $issuedAt = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $issuedAt->getTimestamp(),
            'nbf' => $issuedAt->getTimestamp(),
            'exp' => $issuedAt->add(new DateInterval('PT1H'))->getTimestamp(),
            'auth_provider' => 'email-otp',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        try {
            $this->validatorWithEmptySessions()->validate($token);
            self::fail('Expected expired');
        } catch (AuthTokenValidationFailed $exception) {
            self::assertSame(AuthTokenValidationFailed::AUTH_TOKEN_EXPIRED, $exception->errorCode);
        }
    }

    public function test_future_nbf_rejected(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->add(new DateInterval('PT1H'))->getTimestamp(),
            'exp' => $now->add(new DateInterval('PT2H'))->getTimestamp(),
            'auth_provider' => 'email-otp',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_excessive_ttl_rejected(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => '550e8400-e29b-41d4-a716-446655440099',
            'jti' => '550e8400-e29b-41d4-a716-446655440002',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->add(new DateInterval('P2D'))->getTimestamp(),
            'auth_provider' => 'email-otp',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'guest@example.com',
        ], self::SECRET, 'HS256');

        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_revoked_session_rejected(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        [$token, $validator, $sessions] = $this->issueAndBuildValidator($clock);
        $session = $sessions->all()[0];
        $session->revoke($clock->now());
        $sessions->save($session);

        try {
            $validator->validate($token);
            self::fail('Expected revoked');
        } catch (AuthTokenValidationFailed $exception) {
            self::assertSame(AuthTokenValidationFailed::AUTH_SESSION_REVOKED, $exception->errorCode);
        }
    }

    public function test_token_hash_mismatch_rejected(): void
    {
        [$token, $validator] = $this->issueAndBuildValidator();
        // Valid JWT crypto but no matching session hash when sessions empty.
        $this->expectException(AuthTokenValidationFailed::class);
        $this->validatorWithEmptySessions()->validate($token);
    }

    public function test_composite_routes_guest_by_issuer_hint(): void
    {
        [$token, $guestValidator] = $this->issueAndBuildValidator();
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'GUEST_JWT_ISSUER' => self::ISSUER,
            'GUEST_JWT_AUDIENCE' => self::AUDIENCE,
            'GUEST_JWT_SECRET' => self::SECRET,
            'USER_REPOSITORY' => 'memory',
        ]);

        $entra = new class implements EntraTokenValidator {
            public function validate(string $accessToken): VerifiedIdentity
            {
                throw new EntraTokenValidationFailed('should not be called');
            }
        };

        $result = (new CompositeBearerTokenValidator($entra, static fn () => $guestValidator, $settings))->validate($token);

        self::assertSame('email_otp', $result->identity->provider);
        self::assertSame('guest@example.com', $result->identity->subjectId);
        self::assertSame('guest', $result->identity->tenantId);
        self::assertNotNull($result->guestToken);
        self::assertSame('guest@example.com', $result->guestToken->emailAddress);
    }

    public function test_composite_routes_non_guest_tokens_to_entra(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'GUEST_JWT_ISSUER' => self::ISSUER,
            'GUEST_JWT_AUDIENCE' => self::AUDIENCE,
            'GUEST_JWT_SECRET' => self::SECRET,
            'USER_REPOSITORY' => 'memory',
        ]);

        $entra = new class implements EntraTokenValidator {
            public function validate(string $accessToken): VerifiedIdentity
            {
                return new VerifiedIdentity('microsoft_entra', 'subject', 'tenant');
            }
        };

        $guestValidator = $this->validatorWithEmptySessions();
        $result = (new CompositeBearerTokenValidator(
            $entra,
            static fn () => $guestValidator,
            $settings,
        ))->validate('aaa.bbb.ccc');

        self::assertSame('microsoft_entra', $result->identity->provider);
        self::assertSame('subject', $result->identity->subjectId);
        self::assertNull($result->guestToken);
    }

    /**
     * @return array{0: string, 1: GuestJwtValidator, 2: InMemoryGuestSessionRepository}
     */
    private function issueAndBuildValidator(?FixedClock $clock = null): array
    {
        $clock ??= FixedClock::utc('2026-07-31T00:00:00+00:00');
        $sessions = new InMemoryGuestSessionRepository($clock);
        $users = new StubUserRepository();
        $user = $this->guestUser();
        $users->save($user);

        $hasher = new Sha256SessionTokenHasher();
        $issued = (new IssueGuestSessionService(
            createGuestSession: new CreateGuestSessionService($sessions, clock: $clock),
            tokenIssuer: new GuestJwtIssuer(self::ISSUER, self::AUDIENCE, self::SECRET),
            tokenHasher: $hasher,
            clock: $clock,
            ttlSeconds: 86400,
        ))->execute($user);

        $validator = new GuestJwtValidator(
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
            secret: self::SECRET,
            ttlSeconds: 86400,
            clockSkewSeconds: 30,
            sessions: $sessions,
            tokenHasher: $hasher,
            users: $users,
            clock: $clock,
        );

        return [$issued->accessToken, $validator, $sessions];
    }

    private function validatorWithEmptySessions(): GuestJwtValidator
    {
        return new GuestJwtValidator(
            issuer: self::ISSUER,
            audience: self::AUDIENCE,
            secret: self::SECRET,
            ttlSeconds: 86400,
            clockSkewSeconds: 30,
            sessions: new InMemoryGuestSessionRepository(),
            tokenHasher: new Sha256SessionTokenHasher(),
            users: new StubUserRepository(),
        );
    }

    private function guestUser(): User
    {
        return new User(
            UserId::fromInt(9),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440099'),
            EmailAddress::fromString('guest@example.com'),
            'guest@example.com',
            UserStatus::active(),
            [RoleCode::fromString('GUEST')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
        );
    }

    private function b64(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        self::assertNotFalse($decoded);

        return $decoded;
    }
}
