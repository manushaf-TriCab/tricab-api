<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Default runtime binding until Azure SQL technical schema is applied.
 * Returns no library documents — catalog.json IA inventory remains visible.
 */
final class EmptyTechnicalDocumentLibraryRepository implements TechnicalDocumentLibraryRepository
{
    public function listActiveByCategory(?string $categoryCode = null): array
    {
        unset($categoryCode);

        return [];
    }

    public function findActiveByChecksum(string $checksumSha256): ?TechnicalDocumentRecord
    {
        unset($checksumSha256);

        return null;
    }

    public function findActiveByLegacyId(string $sourceSystemCode, string $sourceLegacyId): ?TechnicalDocumentRecord
    {
        unset($sourceSystemCode, $sourceLegacyId);

        return null;
    }
}
