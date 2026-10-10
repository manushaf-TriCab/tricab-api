<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * What the scan knows about a message when it first looks at it (`GET .../messages/{id}` without the body): the facts
 * `ApEmailService::register()` stores. The mailbox is stored lower-case; a text longer than its column is cut.
 */
final readonly class ApEmailArrival
{
    public function __construct(
        public string $mailbox,
        public string $graphMessageId,
        public ?DateTimeImmutable $receivedAt,
        public string $senderName,
        public string $senderEmail,
        public string $subject,
        public string $bodyPreview,
        public string $webLink,
        public bool $hasAttachments,
        public ?string $scanRunId = null,
    ) {
    }
}
