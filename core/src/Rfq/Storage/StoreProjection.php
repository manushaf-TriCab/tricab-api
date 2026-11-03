<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;
use TriCab\Core\Rfq\Domain\ValueObject\IntakeChannel;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * How the store's source-shaped columns read as the Phase 1 contracts.
 *
 * The tables keep the source's values (§6.5); these are the translations the
 * read side applies, in one place, so that a row and a filter on it cannot
 * disagree.
 *
 *  - **Public id** is the draft id as a UUID: the source's ids are
 *    `uuid4().hex` (all 659 live ones are version 4), so the dashed form is
 *    the same random value, reversible, and an old draft link maps one-to-one.
 *  - **Reference** is `RFQ-` and the draft's sequential number, zero-padded
 *    to six digits (`RFQ-000042`; a number past 999999 shows unpadded). The
 *    number is `rfq_draft_quotes.rfq_number`. A draft without one (the column
 *    not yet filled) falls back to the old reference, `RFQ-` and the id's
 *    first eight hex digits, upper-cased, which stays searchable.
 *  - **Channel:** `email` is the mailbox; every other source value (`web`,
 *    and the staff tools' `alternative` and `quote_import`) is a person on the
 *    website, so `web`.
 *  - **Reads:** the source's `done` is `proposed` while a proposal is
 *    attached and `applied` otherwise (see `ReaderJobStatus`).
 */
final class StoreProjection
{
    public static function publicId(string $draftId): string
    {
        $id = strtolower($draftId);

        return substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20);
    }

    /** The draft id for a public id, or null when it is not one. */
    public static function draftId(string $publicId): ?string
    {
        $hex = str_replace('-', '', strtolower(trim($publicId)));

        return StoreFormat::isId($hex) && self::publicId($hex) === strtolower(trim($publicId)) ? $hex : null;
    }

    /** The reference for a draft: `RFQ-000042` from its number, or the old hex form while it has none. */
    public static function reference(string $draftId, mixed $number = null): string
    {
        $n = is_int($number) ? $number : (is_string($number) && ctype_digit($number) ? (int) $number : 0);

        return $n > 0 ? self::numberReference($n) : self::hexReference($draftId);
    }

    public static function numberReference(int $number): string
    {
        return 'RFQ-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** The reference RFQs had before they were numbered; search still accepts it. */
    public static function hexReference(string $draftId): string
    {
        return 'RFQ-' . strtoupper(substr($draftId, 0, 8));
    }

    /** The source status column, normalised as the source does (`_normalise_status`). */
    public static function sourceStatus(mixed $status): string
    {
        $status = strtolower(PythonText::strip(PythonText::str(DraftChangeLog::truthy($status) ? $status : 'new')));

        return ['pending' => 'new', 'push_failed' => 'failed'][$status] ?? $status;
    }

    public static function workflowStatus(mixed $status): WorkflowStatus
    {
        $raw = strtolower(trim(is_string($status) ? $status : ''));
        $mapped = WorkflowStatus::SOURCE_STATUS_MAP[$raw !== '' ? $raw : 'new'] ?? null;

        return WorkflowStatus::fromStorage($mapped);
    }

    public static function priority(mixed $priority): Priority
    {
        return Priority::fromStorage($priority);
    }

    public static function channel(mixed $source): IntakeChannel
    {
        return IntakeChannel::fromStorage(strtolower(trim(is_string($source) ? $source : '')) === IntakeChannel::EMAIL ? IntakeChannel::EMAIL : IntakeChannel::WEB);
    }

    public static function readerStatus(mixed $status, bool $hasProposal): ReaderJobStatus
    {
        $status = strtolower(trim(is_string($status) ? $status : ''));
        if ($status === 'done') {
            $status = $hasProposal ? ReaderJobStatus::PROPOSED : ReaderJobStatus::APPLIED;
        }

        return ReaderJobStatus::fromStorage($status);
    }

    /**
     * Whether the accounting recorded with a draft's parse balances (plan 3.4):
     * `email_meta.parse.coverage` as `rfq-parser.v1` answered it. Complete only
     * when it was measured and every input row is kept, excluded or skipped.
     * No record (a draft from before the platform measured it, or from the
     * source) is unmeasured, never complete.
     */
    public static function coverageComplete(mixed $coverageJson): bool
    {
        if (!is_string($coverageJson) || $coverageJson === '') {
            return false;
        }
        try {
            $coverage = json_decode($coverageJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($coverage) || ($coverage['measured'] ?? true) !== true) {
            return false;
        }
        $counts = [];
        foreach (['inputRows', 'keptRows', 'excludedRows', 'skippedRows'] as $key) {
            if (!is_int($coverage[$key] ?? null) || $coverage[$key] < 0) {
                return false;
            }
            $counts[$key] = $coverage[$key];
        }

        return $counts['inputRows'] === $counts['keptRows'] + $counts['excludedRows'] + $counts['skippedRows'];
    }

    public static function syncState(mixed $state): SyncAttemptState
    {
        return SyncAttemptState::fromStorage($state);
    }

    /**
     * One `rfq_sync_attempts` row as the contract `rfq.sync-attempt.v1`. The
     * actor, the frozen payload and the result stay in the store: the browser
     * gets the state, how far it got, the quote number and the error.
     *
     * @param array<string, mixed> $row
     */
    public static function syncAttemptView(array $row): SyncAttemptView
    {
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? $v : null;
        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $created = StoreFormat::parseTime($text($row['created_at'] ?? null)) ?? new DateTimeImmutable('@0');

        return new SyncAttemptView(
            self::publicId(is_string($row['draft_id'] ?? null) ? $row['draft_id'] : str_repeat('0', 32)),
            max(1, $int($row['revision'] ?? 1)),
            SyncAttemptState::fromStorage($row['state'] ?? null),
            SyncHeaderState::fromStorage($row['header_state'] ?? null),
            $int($row['attempt_no'] ?? 0),
            $text($row['refno'] ?? null),
            $text($row['refver'] ?? null),
            $int($row['lines_total'] ?? 0),
            $int($row['lines_sent'] ?? 0),
            $int($row['lines_failed'] ?? 0),
            is_string($row['payload_sha256'] ?? null) ? $row['payload_sha256'] : '',
            $text($row['error'] ?? null),
            $text($row['reconcile_note'] ?? null),
            $created,
            StoreFormat::parseTime($text($row['updated_at'] ?? null)) ?? $created,
        );
    }
}
