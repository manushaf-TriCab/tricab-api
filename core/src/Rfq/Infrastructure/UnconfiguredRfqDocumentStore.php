<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;

/** Documents where there is no Azure SQL: a store refuses, a read finds nothing. */
final class UnconfiguredRfqDocumentStore implements RfqDocumentStore
{
    public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument
    {
        throw new RfqStoreUnavailable();
    }

    public function bytes(string $draftId, string $documentId): ?string
    {
        return null;
    }

    public function find(string $draftId, string $documentId): ?SourceDocument
    {
        return null;
    }

    public function forDraft(string $draftId): array
    {
        return [];
    }
}
