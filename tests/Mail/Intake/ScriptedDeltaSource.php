<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailDeltaSource;

/** A delta source that answers from a script: each `changes()` call takes the next page (or throws it). */
final class ScriptedDeltaSource implements MailDeltaSource
{
    /** @var list<MailChanges|\Throwable> */
    public array $pages = [];

    /** @var list<array{?string, DateTimeImmutable}> */
    public array $calls = [];

    /** @var array<string, array<string, string>> */
    public array $headers = [];

    public int $headerFetches = 0;

    public function changes(string $mailbox, ?string $link, DateTimeImmutable $since): MailChanges
    {
        $this->calls[] = [$link, $since];
        $next = array_shift($this->pages);
        if ($next === null) {
            return new MailChanges([], [], $link ?? 'delta-empty', true);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function headers(string $mailbox, string $graphMessageId): array
    {
        ++$this->headerFetches;

        return $this->headers[$graphMessageId] ?? [];
    }
}
