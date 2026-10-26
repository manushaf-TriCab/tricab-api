<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;

/**
 * An extractor without a model: answers `$next`, or else every field null (nothing read, nothing made up), so a
 * person keys the invoice from the original.
 */
final class FakeAp2InvoiceExtractor implements Ap2InvoiceExtractor
{
    public ?Ap2ExtractedInvoice $next = null;

    public ?\Throwable $failWith = null;

    /** @var list<array{contentType: string, fileName: string, supplierHint: ?string, bytes: int}> */
    public array $calls = [];

    public function extract(string $bytes, string $contentType, string $fileName, ?string $supplierHint): Ap2ExtractedInvoice
    {
        $this->calls[] = ['contentType' => $contentType, 'fileName' => $fileName, 'supplierHint' => $supplierHint, 'bytes' => strlen($bytes)];
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->next ?? Ap2ExtractedInvoice::none();
    }
}
