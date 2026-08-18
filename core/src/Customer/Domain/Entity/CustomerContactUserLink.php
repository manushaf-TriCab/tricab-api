<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class CustomerContactUserLink
{
    public function __construct(
        private readonly CustomerContactId $contactId,
        private readonly UserId $userId,
        private readonly bool $isPrimary,
        private readonly bool $isActive,
        private readonly DateTimeImmutable $linkedAt,
        private readonly ?DateTimeImmutable $unlinkedAt = null,
    ) {
    }

    public function contactId(): CustomerContactId
    {
        return $this->contactId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function linkedAt(): DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function unlinkedAt(): ?DateTimeImmutable
    {
        return $this->unlinkedAt;
    }
}
