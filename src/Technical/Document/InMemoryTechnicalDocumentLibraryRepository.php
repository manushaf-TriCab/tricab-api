<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Test/dev repository for Technical library documents.
 */
final class InMemoryTechnicalDocumentLibraryRepository implements TechnicalDocumentLibraryRepository
{
    /** @var list<TechnicalDocumentRecord> */
    private array $documents = [];

    public function seed(TechnicalDocumentRecord $document): void
    {
        $this->documents[] = $document;
    }

    public function listActiveByCategory(?string $categoryCode = null): array
    {
        $out = [];
        foreach ($this->documents as $document) {
            if (!$document->isActive) {
                continue;
            }
            if ($categoryCode !== null && $categoryCode !== '') {
                if (!in_array($categoryCode, $document->categoryCodes, true)) {
                    continue;
                }
            }
            $out[] = $document;
        }

        return $out;
    }

    public function findActiveByChecksum(string $checksumSha256): ?TechnicalDocumentRecord
    {
        $needle = strtolower(trim($checksumSha256));
        foreach ($this->documents as $document) {
            if (!$document->isActive) {
                continue;
            }
            if ($document->checksumSha256 !== null
                && strtolower($document->checksumSha256) === $needle
            ) {
                return $document;
            }
        }

        return null;
    }

    public function findActiveByLegacyId(string $sourceSystemCode, string $sourceLegacyId): ?TechnicalDocumentRecord
    {
        unset($sourceSystemCode);
        foreach ($this->documents as $document) {
            if (!$document->isActive) {
                continue;
            }
            if ($document->sourceLegacyId === $sourceLegacyId) {
                return $document;
            }
        }

        return null;
    }
}
