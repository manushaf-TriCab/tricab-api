<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;

/**
 * A draft creator that behaves as the real one must: one draft per message however often it is asked, and a
 * refusal (nothing created) for as many calls as `$failures` says.
 */
final class RecordingDraftCreator implements RfqDraftCreator
{
    /** @var list<array{threadId: string, messageId: ?string}> every call, failed or not */
    public array $calls = [];

    /** @var array<string, CreatedDraft> keyed by thread and message */
    public array $made = [];

    public int $failures = 0;

    public function createFromThread(MailActor $actor, string $threadId, string $subject, ?string $messageId = null): CreatedDraft
    {
        $this->calls[] = ['threadId' => $threadId, 'messageId' => $messageId];
        if ($this->failures > 0) {
            --$this->failures;
            throw new MailUnavailable('The RFQ parser is down.', MailUnavailable::DRAFTS);
        }
        $key = $threadId . '|' . $messageId;

        return $this->made[$key] ??= new CreatedDraft(
            sprintf('00000000-0000-4000-8000-%012d', count($this->made) + 1),
            'RFQ-' . sprintf('%08d', count($this->made) + 1),
            $subject,
        );
    }
}
