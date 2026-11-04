<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

final readonly class MailReaderOptions
{
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_LIVE = 'live';

    /**
     * @param list<string> $ownAddresses
     */
    public function __construct(
        public string $mailbox,
        public bool $readEnabled,
        public string $mode,
        public int $firstRunDays = 2,
        public int $passSeconds = 100,
        public int $heartbeatMinutes = 360,
        public int $maxPages = 40,
        public int $bodyChars = 2000,
        public int $pageSize = 25,
        /** How long a discovered folder list is trusted before the Inbox subtree is listed again. */
        public int $folderRefreshMinutes = 30,
        /** @var list<string> lower-case folder names left out (with everything below them) */
        public array $excludeFolders = [],
    ) {
    }
}
