<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

/**
 * A drafted email, editable before it is sent: the page reads `{to?, subject, body}`. `to` is where the source would
 * send it ("" when it has no address); nothing here sends anything.
 */
final readonly class ApDraft
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {
    }

    /** @return array{to: string, subject: string, body: string} */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'body' => $this->body, 'to' => $this->to];
    }
}
