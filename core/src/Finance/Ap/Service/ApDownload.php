<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

/** What `POST /invoices/download` answers: the bytes (one file or a zip), their type, the filing name and how many selected invoices had no document. */
final readonly class ApDownload
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $fileName,
        public int $withoutFile,
    ) {
    }
}
