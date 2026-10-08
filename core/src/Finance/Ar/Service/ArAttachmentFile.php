<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

/** A file ready to serve: the bytes, the stored type and the name safe for a header. */
final readonly class ArAttachmentFile
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $downloadName,
    ) {
    }
}
