<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Contract;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

/**
 * Result of provisioning a new Identity user on behalf of the Customer
 * bounded context. Carries both the internal id (needed to create the
 * contact/user link) and the public id (safe to expose to callers).
 */
final readonly class ProvisionedPortalUser
{
    public function __construct(
        public UserId $userId,
        public UserPublicId $userPublicId,
        public EmailAddress $email,
        public string $displayName,
    ) {
    }
}
