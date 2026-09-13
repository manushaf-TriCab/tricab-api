<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use LogicException;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;

final class DisabledStaffEntraLinkWriteUnit implements StaffEntraLinkWriteUnit
{
    public function linkEntraIdentity(array $link): array
    {
        unset($link);
        throw new LogicException('Staff Entra linking requires USER_REPOSITORY=azure-sql.');
    }
}
