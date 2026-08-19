<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\RawRecord;

interface Normalizer
{
    public function normalize(RawRecord $raw): NormalizedRecord;
}
