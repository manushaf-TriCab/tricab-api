<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Authoritative Technical document library (not product.ProductDocuments).
 */
interface TechnicalDocumentLibraryRepository
{
    /**
     * @return list<TechnicalDocumentRecord>
     */
    public function listActiveByCategory(?string $categoryCode = null): array;

    public function findActiveByChecksum(string $checksumSha256): ?TechnicalDocumentRecord;

    public function findActiveByLegacyId(string $sourceSystemCode, string $sourceLegacyId): ?TechnicalDocumentRecord;
}
