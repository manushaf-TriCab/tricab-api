<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use Throwable;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/** Records successful /me sign-in verification, never ordinary API activity or rejected identities. */
final class SuccessfulLoginRecorder
{
    public function __construct(
        private readonly SqlConnection|InMemoryUserManagementStore $store,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function record(AuthenticatedUser $user): void
    {
        try {
            $at = $this->clock->now()->format(DATE_ATOM);
            if ($this->store instanceof InMemoryUserManagementStore) {
                $this->store->recordSuccessfulLogin($user->publicId, $at);
                return;
            }

            $this->store->execute(<<<'SQL'
UPDATE [identity].[Users]
SET [LastSuccessfulLoginAt] = :at
WHERE [PublicId] = :publicId
  AND ([LastSuccessfulLoginAt] IS NULL OR [LastSuccessfulLoginAt] < :newerThan)
SQL, [':at' => $at, ':publicId' => $user->publicId, ':newerThan' => $at]);
        } catch (Throwable $exception) {
            // Recording metadata must not turn a valid sign-in into an outage. Do not log tokens or SQL parameters.
            error_log('Successful login timestamp could not be recorded: ' . $exception::class);
        }
    }
}
