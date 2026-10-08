<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

use DateTimeImmutable;

/** One row of `finance.portal_user_map`: a portal user id and the platform user it became. */
final readonly class FinancePortalUserMapping
{
    public function __construct(
        public string $portalUserId,
        public string $recordedEmail,
        public int $platformUserId,
        public DateTimeImmutable $mappedAt,
    ) {
    }
}
