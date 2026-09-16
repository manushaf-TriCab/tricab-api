<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Persistence;

use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LogNote\Contract\LogNoteAuditRecorder;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class InMemoryLogNoteAuditRecorder implements LogNoteAuditRecorder
{
    /** @var list<array{actor:int,action:string,note:string,customer:string}> */
    private array $events = [];

    public function recordCreated(
        UserId $actorUserId,
        LogNotePublicId $notePublicId,
        CustomerPublicId $customerPublicId,
    ): void {
        $this->events[] = [
            'actor' => $actorUserId->toInt(),
            'action' => 'CUSTOMER_NOTE_CREATED',
            'note' => $notePublicId->toString(),
            'customer' => $customerPublicId->toString(),
        ];
    }

    /** @return list<array{actor:int,action:string,note:string,customer:string}> */
    public function events(): array
    {
        return $this->events;
    }
}
