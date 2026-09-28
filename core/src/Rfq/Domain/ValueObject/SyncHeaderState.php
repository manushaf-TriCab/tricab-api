<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * How far the quote header (4js `QOTHDR`) got, within one sync attempt.
 *
 *  - `pending`   — nothing sent. Safe to retry.
 *  - `sent`      — written **before** the request leaves. A crash here means
 *                  4js may or may not hold a quote.
 *  - `confirmed` — 4js returned a quote number. Lines resume against it.
 *  - `rejected`  — 4js answered no. Certain; a corrected retry is safe.
 *  - `unknown`   — a timeout or dropped connection after sending. Not certain.
 *
 * The ordering rule — `sent` before the request, not after — is what makes an
 * interrupted push distinguishable from one that never started (source
 * `note_sync_header_intent`).
 */
final class SyncHeaderState extends Vocabulary
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';

    public static function values(): array
    {
        return [self::PENDING, self::SENT, self::CONFIRMED, self::REJECTED, self::UNKNOWN];
    }

    /** `unknown`: the only reading that sends nobody to retry blind. */
    protected static function fallback(): string
    {
        return self::UNKNOWN;
    }

    protected static function label(): string
    {
        return 'sync header state';
    }

    /** 4js may hold a quote whose number nobody recorded. */
    public function mayHaveCreatedQuote(): bool
    {
        return $this->isOneOf([self::SENT, self::UNKNOWN]);
    }
}
