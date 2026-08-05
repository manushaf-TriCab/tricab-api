<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * Withdraws a document from public availability (business action). The
 * underlying repository only exposes an archive() (soft-delete) primitive,
 * so this and SoftDeleteProductDocumentCommand both call it — they are kept
 * as distinct commands/services because they carry different audit intent.
 */
final readonly class WithdrawProductDocumentCommand
{
    public function __construct(
        public string $productPublicId,
        public string $documentPublicId,
    ) {
    }
}
