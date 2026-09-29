<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use TriCab\Core\Rfq\Domain\ValueObject\IntakeChannel;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
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
 *  - **Reference** is `RFQ-` and the id's first eight hex digits, upper-cased:
 *    the source has no human reference of its own, and this one is stable
 *    and searchable without a column.
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

    public static function reference(string $draftId): string
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
}
