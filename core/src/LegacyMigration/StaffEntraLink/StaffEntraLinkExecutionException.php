<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

use RuntimeException;

final class StaffEntraLinkExecutionException extends RuntimeException
{
    public static function stalePreview(string $expected, string $current): self
    {
        return new self(sprintf('STALE_ENTRA_LINK_PREVIEW: expected fingerprint %s but current is %s.', $expected, $current));
    }

    public static function countMismatch(string $metric, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Execution blocked: %s expected %d but fresh revalidation found %d.',
            $metric,
            $expected,
            $actual,
        ));
    }

    public static function mappingCountMismatch(int $expected, int $actual): self
    {
        return new self(sprintf(
            'STOP: expected %d staff mappings but found %d.',
            $expected,
            $actual,
        ));
    }

    public static function schemaMissing(string $object): self
    {
        return new self('Required migration schema object missing: ' . $object);
    }

    public static function persistenceModeInvalid(string $mode): self
    {
        return new self('LEGACY_MIGRATION_STORE must be azure-sql for execution. Current: ' . $mode);
    }
}
