<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/**
 * The read side of `finance.access_scopes`: what a platform user is limited to in one
 * finance app. Read only; rows arrive with an app's data migration or an
 * administrator's script, in that app's phase.
 */
interface FinanceAccessScopeReader
{
    public function forUser(int $userId, string $application): FinanceAccessScope;
}
