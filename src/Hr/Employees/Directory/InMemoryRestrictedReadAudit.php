<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class InMemoryRestrictedReadAudit implements RestrictedReadAudit
{
    /** @var list<array{actorUserId: int, employeeCode: string, endpoint: string}> */
    public array $events = [];

    public function record(ActorContext $actor, string $employeeCode, string $endpoint): void
    {
        $this->events[] = ['actorUserId' => $actor->userId, 'employeeCode' => $employeeCode, 'endpoint' => $endpoint];
    }
}
