<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

/**
 * One attachment of a message, read live from the mailbox, ready to serve: the bytes, the type the sender gave (parameters
 * cut, lower case; `application/octet-stream` when there is none), a name safe for a header, and whether the type is one a
 * browser may show in place ({@see ApMessageService::INLINE_TYPES}). The content is untrusted.
 */
final readonly class ApMessageFile
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $downloadName,
        public bool $inlineAllowed,
    ) {
    }
}
