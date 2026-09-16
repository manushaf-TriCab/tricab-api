<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Contract;

use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

interface LogNoteAuditRecorder
{
    public function recordCreated(
        UserId $actorUserId,
        LogNotePublicId $notePublicId,
        CustomerPublicId $customerPublicId,
    ): void;
}
