<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;

/** AI not connected: every field null. The invoice is made empty and lands in "Needs review". */
final class NullAp2InvoiceExtractor implements Ap2InvoiceExtractor
{
    public function extract(string $bytes, string $contentType, string $fileName, ?string $supplierHint): Ap2ExtractedInvoice
    {
        return Ap2ExtractedInvoice::none();
    }
}
