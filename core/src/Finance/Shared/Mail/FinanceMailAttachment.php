<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * One file on an outgoing message. `content` is the raw bytes, not base64: the
 * transport encodes it, and the size rule counts what the recipient receives.
 */
final readonly class FinanceMailAttachment
{
    public function __construct(
        public string $name,
        public string $content,
        public string $contentType = 'application/octet-stream',
    ) {
    }
}
