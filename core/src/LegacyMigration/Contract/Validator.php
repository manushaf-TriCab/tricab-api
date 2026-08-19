<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

interface Validator
{
    /**
     * @param list<NormalizedRecord> $records
     * @return list<ValidationFinding>
     */
    public function validate(array $records): array;
}
