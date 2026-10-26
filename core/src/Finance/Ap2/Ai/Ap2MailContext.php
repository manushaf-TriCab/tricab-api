<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/** The mail a draft or an answer is about. Plain text only; the body is untrusted supplier text. */
final readonly class Ap2MailContext
{
    /**
     * @param list<string> $attachmentNames
     */
    public function __construct(
        public string $id,
        public string $subject,
        public string $from,
        public string $bodyText,
        /** One of `Ap2Mail::CATEGORIES`. */
        public string $category,
        /** ISO UTC (`2026-10-26T01:02:03Z`). */
        public string $receivedAt,
        public array $attachmentNames = [],
    ) {
    }
}
