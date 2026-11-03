<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

/** The one shared assistant conversation of an RFQ draft. */
final readonly class QuotegenConversation
{
    public function __construct(
        public string $id,
        public string $rfqPublicId,
    ) {
    }
}
