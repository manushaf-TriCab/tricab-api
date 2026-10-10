<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

/**
 * The scanned mailbox email a reply is drafted or suggested for: the columns of the source's `emails` row that its
 * prompts read (`sender_name`, `sender_email`, `subject`, `body_preview`).
 *
 * The source's reply-draft prompt prints `email.get('sender')` after the name, and an `emails` row has no `sender`
 * column, so its "From:" line always read `Name <>` (fault R10). That is fixed (T6.10): the address is sent as `sender`
 * too, so the line reads `Name <address>`. The suggestion prompt prints `sender_email`, as the source's did.
 */
final readonly class ApDraftEmail
{
    public function __construct(
        public ?string $senderName,
        public ?string $senderEmail,
        public ?string $subject,
        public ?string $bodyPreview,
    ) {
    }

    /** @return array{sender_name: string, sender: string, sender_email: string, subject: string, body_preview: string} */
    public function promptData(): array
    {
        return [
            'sender_name' => $this->senderName ?? '',
            'sender' => $this->senderEmail ?? '',
            'sender_email' => $this->senderEmail ?? '',
            'subject' => $this->subject ?? '',
            'body_preview' => $this->bodyPreview ?? '',
        ];
    }

    /** `email.get("sender_email") or ""`: where the reply goes. */
    public function to(): string
    {
        return $this->senderEmail ?? '';
    }
}
