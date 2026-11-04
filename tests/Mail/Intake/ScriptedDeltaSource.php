<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailDeltaSource;
use TriCab\Core\Mail\Intake\MailFolder;

/** A delta source that answers from a script: each `changes()` call takes the next page (or throws it). */
final class ScriptedDeltaSource implements MailDeltaSource
{
    /** @var list<MailChanges|\Throwable> */
    public array $pages = [];

    /** @var list<array{?string, DateTimeImmutable}> */
    public array $calls = [];

    /** @var list<MailFolder> what `folders()` answers; the Inbox alone unless a test adds more */
    public array $folderList = [];

    /** @var array<string, list<MailChanges|\Throwable>> pages for a folder other than the Inbox, by folder id */
    public array $folderPages = [];

    /** @var list<string> the folder of every `changes()` call, in order */
    public array $folderCalls = [];

    public int $folderListings = 0;

    public ?\Throwable $folderListFailure = null;

    /** @var array<string, array<string, string>> */
    public array $headers = [];

    public int $headerFetches = 0;

    public function folders(string $mailbox): array
    {
        ++$this->folderListings;
        if ($this->folderListFailure !== null) {
            throw $this->folderListFailure;
        }

        return [MailFolder::inbox(), ...$this->folderList];
    }

    public function changes(string $mailbox, ?string $link, DateTimeImmutable $since, string $folderId = MailFolder::INBOX): MailChanges
    {
        $this->calls[] = [$link, $since];
        $this->folderCalls[] = $folderId;
        $next = $folderId === MailFolder::INBOX ? array_shift($this->pages) : $this->nextFolderPage($folderId);
        if ($next === null) {
            return new MailChanges([], [], $link ?? 'delta-empty' . ($folderId === MailFolder::INBOX ? '' : ':' . $folderId), true);
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

    /**
     * The link each `changes()` call for this folder was given (null = started from the window).
     *
     * @return list<?string>
     */
    public function linksFor(string $folderId): array
    {
        $links = [];
        foreach ($this->folderCalls as $i => $id) {
            if ($id === $folderId) {
                $links[] = $this->calls[$i][0];
            }
        }

        return $links;
    }

    private function nextFolderPage(string $folderId): MailChanges|\Throwable|null
    {
        $pages = $this->folderPages[$folderId] ?? [];
        $next = array_shift($pages);
        $this->folderPages[$folderId] = $pages;

        return $next;
    }
}
