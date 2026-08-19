<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Extractor;
use TriCab\Core\LegacyMigration\Support\RawRecord;

/**
 * Non-production extractor. Never connects to WordPress, Moodle, or the business DB.
 */
final class FixtureExtractor implements Extractor
{
    /**
     * @param list<array<string, mixed>> $fixtures
     */
    public function __construct(
        private readonly string $moduleCode,
        private readonly string $sourceTable,
        private readonly array $fixtures,
    ) {
    }

    public function extract(array $filter): iterable
    {
        unset($filter);
        foreach ($this->fixtures as $payload) {
            $id = (string) ($payload['legacyId'] ?? $payload['id'] ?? '');
            yield new RawRecord(
                sourceSystem: 'fixture',
                sourceTable: $this->sourceTable,
                sourceIdentifier: $id,
                payload: $payload,
                recordType: $this->moduleCode,
            );
        }
    }

    public function supportsIncremental(): bool
    {
        return true;
    }
}
