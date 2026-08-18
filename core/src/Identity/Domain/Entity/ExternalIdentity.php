<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Linked external identity for a platform user.
 * Does not parse or validate identity-provider tokens.
 */
final class ExternalIdentity
{
    public function __construct(
        private readonly ExternalProvider $provider,
        private readonly ExternalSubjectId $subjectId,
        private readonly ExternalTenantId $tenantId,
    ) {
    }

    public function provider(): ExternalProvider
    {
        return $this->provider;
    }

    public function subjectId(): ExternalSubjectId
    {
        return $this->subjectId;
    }

    public function tenantId(): ExternalTenantId
    {
        return $this->tenantId;
    }

    public function equals(self $other): bool
    {
        return $this->provider->equals($other->provider)
            && $this->subjectId->equals($other->subjectId)
            && $this->tenantId->equals($other->tenantId);
    }
}
