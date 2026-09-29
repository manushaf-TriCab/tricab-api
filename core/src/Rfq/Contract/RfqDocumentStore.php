<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\SourceDocument;

/**
 * The files an RFQ was read from (plan 2.5): bytes in a private blob
 * container under a generated key, metadata in `rfq_documents`. The file name
 * is display metadata only; no key or path leaves this store.
 */
interface RfqDocumentStore
{
    public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument;

    /** The bytes of one of this draft's documents, or null when there is no such document. */
    public function bytes(string $draftId, string $documentId): ?string;

    public function find(string $draftId, string $documentId): ?SourceDocument;

    /** @return list<SourceDocument> */
    public function forDraft(string $draftId): array;
}
