<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use TriCab\Core\Mail\Contract\RfqFollowupProposer;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/** Records what the automation asked to propose. */
final class RecordingFollowupProposer implements RfqFollowupProposer
{
    /** @var list<array{threadId: string, messageId: string, draft: string}> */
    public array $calls = [];

    public int $failures = 0;

    public function propose(string $threadId, string $messageId, string $draftPublicId): string
    {
        $this->calls[] = ['threadId' => $threadId, 'messageId' => $messageId, 'draft' => $draftPublicId];
        if ($this->failures > 0) {
            --$this->failures;

            throw new MailUnavailable('parser down', MailUnavailable::DRAFTS);
        }

        return self::PROPOSED;
    }
}
