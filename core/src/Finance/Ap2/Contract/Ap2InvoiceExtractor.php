<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;

/**
 * Reads an invoice document (a PDF or an image) into fields. Called by `POST /mail/{id}/create-invoice`; an invoice
 * built from nulls simply lands in the "Needs review" queue, so a null is always acceptable.
 *
 * `$supplierHint` is the linked supplier's name, or null.
 */
interface Ap2InvoiceExtractor
{
    public function extract(string $bytes, string $contentType, string $fileName, ?string $supplierHint): Ap2ExtractedInvoice;
}
