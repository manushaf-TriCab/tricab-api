<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use Throwable;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\IssuedGuestSession;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Issues a guest JWT and persists only the token hash + session metadata.
 * Plaintext token is returned once and never logged.
 */
final class IssueGuestSessionService
{
    public const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly CreateGuestSessionService $createGuestSession,
        private readonly GuestAccessTokenIssuer $tokenIssuer,
        private readonly SessionTokenHasher $tokenHasher,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    public function execute(
        User $user,
        ?string $createdIpAddress = null,
        ?string $createdUserAgent = null,
        string $authProvider = 'email-otp',
    ): IssuedGuestSession {
        $user->assertAuthenticationAllowed();

        $issuedAtUtc = $this->clock->now();
        $expiresAtUtc = $issuedAtUtc->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $sessionPublicId = $this->uuidGenerator->generate();
        $jwtId = $this->uuidGenerator->generate();

        $issuedToken = $this->tokenIssuer->issue(
            user: $user,
            sessionPublicId: $sessionPublicId,
            jwtId: $jwtId,
            issuedAtUtc: $issuedAtUtc,
            expiresAtUtc: $expiresAtUtc,
            authProvider: $authProvider,
        );

        $tokenHash = $this->tokenHasher->hash($issuedToken->token);

        try {
            $this->createGuestSession->execute(
                user: $user,
                sessionTokenHash: $tokenHash,
                jwtId: $jwtId,
                issuedAtUtc: $issuedAtUtc,
                expiresAtUtc: $expiresAtUtc,
                createdIpAddress: $createdIpAddress,
                createdUserAgent: $createdUserAgent,
                publicId: $sessionPublicId,
            );
        } catch (Throwable $exception) {
            throw GuestSessionIssuanceFailed::persistenceFailed($exception);
        }

        $authenticated = AuthenticatedUser::fromUser($user);

        return new IssuedGuestSession(
            accessToken: $issuedToken->token,
            tokenType: 'Bearer',
            expiresIn: $this->ttlSeconds,
            expiresAtUtc: $expiresAtUtc->format(DATE_ATOM),
            user: [
                'userId' => $authenticated->userId,
                'publicId' => $authenticated->publicId,
                'emailAddress' => $authenticated->emailAddress,
                'displayName' => $authenticated->displayName,
                'status' => $authenticated->status,
                'roles' => $authenticated->roles,
                'permissions' => $authenticated->permissions,
            ],
        );
    }
}
