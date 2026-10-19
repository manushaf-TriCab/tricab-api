<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/**
 * One page of a delta query. `link` is what to resume from once this page is handled: the page's
 * `@odata.nextLink` while more follow, or the final `@odata.deltaLink` (then `complete` is true).
 */
final readonly class MailChanges
{
    /**
     * @param list<IncomingMail> $messages
     * @param list<string> $removedIds Graph ids Graph reports as deleted or moved out of the Inbox
     */
    public function __construct(
        public array $messages,
        public array $removedIds,
        public string $link,
        public bool $complete,
    ) {
    }
}
