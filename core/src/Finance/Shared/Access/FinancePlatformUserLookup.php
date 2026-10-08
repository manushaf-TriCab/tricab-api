<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/** Finds platform users by the email addresses they hold (`identity`). Read only. */
interface FinancePlatformUserLookup
{
    /**
     * @param list<string> $emails lower-case, trimmed
     * @return array<string, list<int>> email => the platform users that hold it (one, or none)
     */
    public function userIdsByEmail(array $emails): array;
}
