<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

/** An invoice's document ready to serve: the bytes, the stored type, the name safe for a header, and whether a browser may show it inline. */
final readonly class ApAttachmentFile
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $downloadName,
        public bool $inlineAllowed,
    ) {
    }
}
