<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * One durable attempt to put one immutable RFQ revision into 4js.
 *
 * Ported from `rfq_sync_attempts.state` in the source, where it is the part of
 * the application most worth keeping. `uncertain` is the load-bearing value:
 * a timeout after the quote header may have been accepted. It is never retried
 * and never superseded — only a person who has looked in 4js resolves it
 * (invariant 8). Transitions: `SyncAttemptTransitionPolicy`.
 */
final class SyncAttemptState extends Vocabulary
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const UNCERTAIN = 'uncertain';
    public const SUPERSEDED = 'superseded';

    public const FINAL = [self::SUCCEEDED, self::FAILED, self::SUPERSEDED];

    public static function values(): array
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::SUCCEEDED,
            self::FAILED,
            self::UNCERTAIN,
            self::SUPERSEDED,
        ];
    }

    /**
     * `uncertain`: an attempt whose state cannot be read might have created a
     * quote. Any other reading either retries it — a duplicate quote — or
     * forgets it.
     */
    protected static function fallback(): string
    {
        return self::UNCERTAIN;
    }

    protected static function label(): string
    {
        return 'sync attempt state';
    }

    public static function queued(): self
    {
        return new self(self::QUEUED);
    }

    public function isFinal(): bool
    {
        return $this->isOneOf(self::FINAL);
    }
}
