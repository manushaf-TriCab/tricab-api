<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserProvisioningRepository;
use TriCab\Api\Identity\Persistence\IdentityPersistenceException;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\Doubles\StubUserRepository;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class AzureSqlGuestPersistenceTest extends TestCase
{
    public function test_create_and_find_active_email_otp_challenge(): void
    {
        $now = new DateTimeImmutable('2026-07-31 00:00:00', new DateTimeZone('UTC'));
        $challenge = EmailOtpChallenge::request(
            publicId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            emailAddress: EmailAddress::fromString('guest@example.com'),
            otpHash: '$2y$10$examplehashvalueexamplehashvalueexampl',
            purpose: OtpPurpose::guestLogin(),
            createdAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval('PT10M')),
        );

        $connection = new FakeSqlConnection();
        $connection->queueFetch([['EmailOtpChallengeId' => 11, 'PublicId' => '550e8400-e29b-41d4-a716-446655440001']]);
        $connection->queueFetch([$this->challengeRow()]);

        $repository = new AzureSqlEmailOtpChallengeRepository($connection);
        $repository->save($challenge);

        self::assertNotNull($challenge->id());
        self::assertSame(11, $challenge->id()->toInt());
        self::assertStringContainsString('INSERT INTO [identity].[EmailOtpChallenges]', $connection->calls[0]['sql']);
        self::assertSame('guest@example.com', $connection->calls[0]['params'][':email']);
        self::assertStringNotContainsString("N'", $connection->calls[0]['sql']);

        $found = $repository->findLatestActiveByEmailAndPurpose(
            EmailAddress::fromString('guest@example.com'),
            OtpPurpose::guestLogin(),
        );

        self::assertNotNull($found);
        self::assertSame(11, $found->id()?->toInt());
        self::assertStringContainsString('ExpiresAtUtc] > SYSUTCDATETIME()', $connection->calls[1]['sql']);
    }

    public function test_invalidate_and_consume_challenge(): void
    {
        $connection = new FakeSqlConnection();
        $repository = new AzureSqlEmailOtpChallengeRepository($connection);

        $repository->invalidateActiveChallenges(
            EmailAddress::fromString('guest@example.com'),
            OtpPurpose::guestLogin(),
        );

        self::assertStringContainsString('UPDATE [identity].[EmailOtpChallenges]', $connection->calls[0]['sql']);
        self::assertStringContainsString('[InvalidatedAtUtc] = SYSUTCDATETIME()', $connection->calls[0]['sql']);

        $challenge = EmailOtpChallenge::reconstitute(
            id: EmailOtpChallengeId::fromInt(11),
            publicId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            emailAddress: EmailAddress::fromString('guest@example.com'),
            otpHash: 'hash',
            purpose: OtpPurpose::guestLogin(),
            expiresAtUtc: new DateTimeImmutable('2026-07-31 00:10:00', new DateTimeZone('UTC')),
            attemptCount: 1,
            maximumAttempts: 5,
            consumedAtUtc: new DateTimeImmutable('2026-07-31 00:05:00', new DateTimeZone('UTC')),
            invalidatedAtUtc: null,
            requestedIpAddress: null,
            requestedUserAgent: null,
            createdAtUtc: new DateTimeImmutable('2026-07-31 00:00:00', new DateTimeZone('UTC')),
        );

        $repository->save($challenge);

        self::assertStringContainsString('UPDATE [identity].[EmailOtpChallenges]', $connection->calls[1]['sql']);
        self::assertSame(1, $connection->calls[1]['params'][':attemptCount']);
        self::assertNotNull($connection->calls[1]['params'][':consumedAtUtc']);
    }

    public function test_create_guest_session_persists_token_hash_and_timestamps(): void
    {
        $now = new DateTimeImmutable('2026-07-31 00:00:00', new DateTimeZone('UTC'));
        $session = GuestSession::open(
            publicId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440002'),
            userId: UserId::fromInt(42),
            sessionTokenHash: SessionTokenHash::fromHex(str_repeat('ab', 32)),
            issuedAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval('PT12H')),
            jwtId: UuidPublicId::fromString('550e8400-e29b-41d4-a716-446655440003'),
        );

        $connection = new FakeSqlConnection();
        $connection->queueFetch([['GuestSessionId' => 7]]);
        $connection->queueFetch([$this->sessionRow()]);

        $repository = new AzureSqlGuestSessionRepository($connection);
        $repository->save($session);

        self::assertSame(7, $session->id()?->toInt());
        self::assertSame(42, $connection->calls[0]['params'][':userId']);
        $hashParam = $connection->calls[0]['params'][':sessionTokenHash'];
        self::assertInstanceOf(\TriCab\Api\Infrastructure\Database\SqlBinary::class, $hashParam);
        self::assertSame(32, $hashParam->length());
        self::assertSame(str_repeat('ab', 32), bin2hex($hashParam->bytes));
        self::assertSame('550e8400-e29b-41d4-a716-446655440003', $connection->calls[0]['params'][':jwtId']);
        self::assertStringNotContainsString("N'", $connection->calls[0]['sql']);

        $found = $repository->findBySessionTokenHash(SessionTokenHash::fromHex(str_repeat('ab', 32)));
        self::assertNotNull($found);
        self::assertSame(7, $found->id()?->toInt());
    }

    public function test_provision_guest_resolves_role_by_code_inside_transaction(): void
    {
        $users = new StubUserRepository();
        $users->userByEmail = new User(
            UserId::fromInt(99),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440099'),
            EmailAddress::fromString('guest@example.com'),
            'guest@example.com',
            UserStatus::active(),
            [RoleCode::fromString('GUEST')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
        );

        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 1]]);
        $connection->queueFetch([['UserId' => 99]]);
        $connection->queueFetch([['RoleId' => 5]]);

        $repository = new AzureSqlUserProvisioningRepository(
            users: $users,
            connection: $connection,
            uuidGenerator: new UuidV4Generator(),
        );

        $identity = new ExternalIdentity(
            ExternalProvider::emailOtp(),
            ExternalSubjectId::fromString('guest@example.com'),
            ExternalTenantId::fromString('guest'),
        );

        $user = $repository->register(
            emailAddress: EmailAddress::fromString('guest@example.com'),
            displayName: 'guest@example.com',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: $identity,
        );

        self::assertSame(99, $user->id()->toInt());
        self::assertTrue($connection->beganTransaction);
        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);

        $sql = implode("\n", array_map(static fn (array $call): string => $call['sql'], $connection->calls));
        self::assertStringContainsString('INSERT INTO [identity].[Users]', $sql);
        self::assertStringContainsString('INSERT INTO [identity].[ExternalIdentities]', $sql);
        self::assertStringContainsString('INSERT INTO [identity].[UserRoles]', $sql);
        self::assertStringContainsString('UPPER([RoleCode]) = :roleCode', $sql);
        self::assertSame('GUEST', $this->paramFromCalls($connection, ':roleCode'));
    }

    public function test_provision_rolls_back_when_insert_fails(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 1]]);
        $connection->failNextWrite = new RuntimeException('simulated insert failure');

        $repository = new AzureSqlUserProvisioningRepository(
            users: new StubUserRepository(),
            connection: $connection,
        );

        try {
            $repository->register(
                emailAddress: EmailAddress::fromString('guest@example.com'),
                displayName: 'guest@example.com',
                status: UserStatus::active(),
                roles: [RoleCode::fromString('GUEST')],
                permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
                identity: new ExternalIdentity(
                    ExternalProvider::emailOtp(),
                    ExternalSubjectId::fromString('guest@example.com'),
                    ExternalTenantId::fromString('guest'),
                ),
            );
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated insert failure', $exception->getMessage());
        }

        self::assertTrue($connection->beganTransaction);
        self::assertTrue($connection->rolledBack);
        self::assertFalse($connection->committed);
    }

    public function test_provision_fails_when_guest_role_missing(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['UserStatusId' => 1]]);
        $connection->queueFetch([['UserId' => 99]]);
        $connection->queueFetch([]); // role missing

        $repository = new AzureSqlUserProvisioningRepository(
            users: new StubUserRepository(),
            connection: $connection,
        );

        $this->expectException(IdentityPersistenceException::class);
        $this->expectExceptionMessage('Active role "GUEST" was not found.');

        try {
            $repository->register(
                emailAddress: EmailAddress::fromString('guest@example.com'),
                displayName: 'guest@example.com',
                status: UserStatus::active(),
                roles: [RoleCode::fromString('GUEST')],
                permissions: [],
                identity: new ExternalIdentity(
                    ExternalProvider::emailOtp(),
                    ExternalSubjectId::fromString('guest@example.com'),
                    ExternalTenantId::fromString('guest'),
                ),
            );
        } finally {
            self::assertTrue($connection->rolledBack);
        }
    }

    private function paramFromCalls(FakeSqlConnection $connection, string $name): mixed
    {
        foreach ($connection->calls as $call) {
            if (array_key_exists($name, $call['params'])) {
                return $call['params'][$name];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeRow(): array
    {
        return [
            'EmailOtpChallengeId' => 11,
            'PublicId' => '550e8400-e29b-41d4-a716-446655440001',
            'EmailAddress' => 'guest@example.com',
            'OtpHash' => '$2y$10$examplehashvalueexamplehashvalueexampl',
            'PurposeCode' => 'guest_login',
            'ExpiresAtUtc' => '2026-07-31 00:10:00.000000',
            'AttemptCount' => 0,
            'MaximumAttempts' => 5,
            'ConsumedAtUtc' => null,
            'InvalidatedAtUtc' => null,
            'RequestedIpAddress' => null,
            'RequestedUserAgent' => null,
            'CreatedAtUtc' => '2026-07-31 00:00:00.000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(): array
    {
        return [
            'GuestSessionId' => 7,
            'PublicId' => '550e8400-e29b-41d4-a716-446655440002',
            'UserId' => 42,
            'SessionTokenHash' => hex2bin(str_repeat('ab', 32)),
            'JwtId' => '550e8400-e29b-41d4-a716-446655440003',
            'IssuedAtUtc' => '2026-07-31 00:00:00.000000',
            'ExpiresAtUtc' => '2026-07-31 12:00:00.000000',
            'RevokedAtUtc' => null,
            'LastUsedAtUtc' => null,
            'CreatedIpAddress' => null,
            'CreatedUserAgent' => null,
            'CreatedAtUtc' => '2026-07-31 00:00:00.000000',
        ];
    }
}
