<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** What the classifier is shown about one message. The text is untrusted. */
final readonly class ClassifierInput
{
    /** @param list<array{name: string, type: string}> $attachments */
    public function __construct(
        public string $subject,
        public string $senderName,
        public string $senderAddress,
        /** The first ~2,000 characters of the body as plain text. Never stored. */
        public string $bodyText,
        public array $attachments,
        public bool $conversationHasDraft,
    ) {
    }
}
