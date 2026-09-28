<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The scalar formats of the RFQ store's columns, as the source writes them.
 *
 *  - Ids are `uuid.uuid4().hex`: 32 lower-case hex digits, version 4.
 *  - Times are `datetime.now(timezone.utc).isoformat()`:
 *    `2026-09-28T01:02:03.456789+00:00`, with the fraction left out when it is
 *    zero, as Python does. Text, because the columns are text (§6.5).
 *  - A new draft is due 72 hours after it is created (`DEFAULT_DUE_HOURS`).
 *  - Emails lower-cased and stripped; branches upper-cased and stripped.
 */
final class StoreFormat
{
    public const DEFAULT_DUE_HOURS = 72;

    /** The source's six `rfq_draft_quotes.status` values. */
    public const STATUSES = ['new', 'in_progress', 'ready_to_sync', 'blocked', 'failed', 'synced'];

    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return bin2hex($bytes);
    }

    public static function isId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    public static function isoTime(DateTimeImmutable $time): string
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        $micro = (int) $utc->format('u');

        return $utc->format('Y-m-d\TH:i:s') . ($micro !== 0 ? sprintf('.%06d', $micro) : '') . '+00:00';
    }

    /**
     * Reads either shape the store holds: the ISO text above, and the 39 July
     * `due_at` values written as `YYYY-MM-DD HH:MM:SS` with no zone (UTC).
     */
    public static function parseTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s.u', 'Y-m-d\TH:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, trim($value), $utc);
            if ($parsed !== false) {
                return $parsed->setTimezone($utc);
            }
        }

        return null;
    }

    public static function email(mixed $value): string
    {
        return mb_strtolower(PythonText::strip(PythonText::str(DraftChangeLog::truthy($value) ? $value : '')), 'UTF-8');
    }

    public static function branch(mixed $value): string
    {
        return PythonText::upper(PythonText::strip(PythonText::str(DraftChangeLog::truthy($value) ? $value : '')));
    }
}
