<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

/** A file ready to serve: a header-safe name, its type, its bytes and their SHA-256. */
final readonly class Ap2File
{
    public function __construct(
        public string $name,
        public string $contentType,
        public string $bytes,
        public string $sha256,
    ) {
    }
}
