<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoredDraft;

/**
 * Writes RFQ drafts into the store (`[rfq]`, the live MySQL shape, §6.5).
 *
 * **Every write is one transaction**: the draft, its lines, its row edits and
 * its activity arrive together or not at all. A content save locks the draft
 * row first and refuses a stale revision, so two people saving the same
 * revision cannot both win; the loser gets {@see RfqRevisionConflict} and
 * nothing of theirs is written.
 *
 * The store decides no business rule. Who may save is the access policy's;
 * the status a save leaves is the readiness rule's, passed in. What the store
 * owns is the source's storage behaviour: line identity, the parser snapshot,
 * the change log and the formats, so that platform writes and migrated rows
 * cannot be told apart.
 */
interface RfqDraftStore
{
    /** @return string the new draft's id */
    public function create(NewDraft $draft): string;

    /**
     * The source's `update_draft`: new header and lines, revision + 1. The
     * actor becomes the sales rep, assignee and 4js author
     * ({@see \TriCab\Core\Rfq\Storage\SalesRepAttribution}).
     *
     * @param array<string, mixed>       $quoteDetails
     * @param list<array<string, mixed>> $rows
     * @param string                     $nextStatus one of `StoreFormat::STATUSES`
     *
     * @return int|null the new revision; null when there is no such draft
     *
     * @throws RfqRevisionConflict when `$expectedRevision` is not the current one
     * @throws RfqDraftNotEditable when a sync is running or the draft is archived
     */
    public function saveContent(
        string $draftId,
        int $expectedRevision,
        array $quoteDetails,
        array $rows,
        string $nextStatus,
        DraftActor $actor,
        string $viewMode = '',
    ): ?int;

    public function load(string $draftId): ?StoredDraft;
}
