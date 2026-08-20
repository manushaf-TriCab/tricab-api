<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Mapper;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;

final class StagingMapper implements Mapper
{
    public function map(NormalizedRecord $record): array
    {
        return [
            'moduleCode' => $record->moduleCode,
            'legacyId' => $record->sourceIdentifier,
            'canonical' => $record->canonical,
            'sourceHash' => $record->sourceHash,
            'target' => 'staging-snapshot',
        ];
    }
}
