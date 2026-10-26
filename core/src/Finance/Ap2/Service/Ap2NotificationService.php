<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Contract\Ap2NotificationStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;

/** The signed-in person's bell. Nobody sees anyone else's. */
final class Ap2NotificationService
{
    public function __construct(private readonly Ap2NotificationStore $notifications)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(Ap2Actor $actor): array
    {
        return array_map(Ap2Presenter::notification(...), $this->notifications->forUser($actor->userId));
    }

    /** @return array{read: true} */
    public function readAll(Ap2Actor $actor): array
    {
        $this->notifications->markAllRead($actor->userId);

        return ['read' => true];
    }
}
