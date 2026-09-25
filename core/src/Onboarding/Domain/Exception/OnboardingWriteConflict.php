<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * Two writes to the same thing, decided against the second one.
 *
 * Three different races arrive here, and they are named separately because the
 * SPA does something different with each:
 *
 *  - {@see staleToken()} — optimistic concurrency. Somebody saved between this
 *    client's read and its write. Phase 2.5 established the mechanism: a
 *    `RowVersionToken`, and an `UPDATE … WHERE [RowVersion] = :expected` whose
 *    empty result IS the conflict. Reload and reapply.
 *  - {@see idempotencyKeyReused()} — the same key with a different request body.
 *    A replay carrying different content is a client defect, and answering it
 *    with the first call's result would silently discard the second. Never a
 *    retry.
 *  - {@see alreadyRecorded()} — the work is immutable and is already there. An
 *    acknowledgement or a submitted answer cannot be rewritten; the schema's
 *    triggers refuse it even if this class did not.
 *
 * Maps to 409 in all three cases. The code on the response is what distinguishes
 * them.
 */
final class OnboardingWriteConflict extends DomainException
{
    /** Machine-readable code, mirrored by the API's error envelope. */
    public const STALE_TOKEN = 'ONBOARDING_STALE_TOKEN';

    public const IDEMPOTENCY_KEY_REUSED = 'ONBOARDING_IDEMPOTENCY_KEY_REUSED';

    public const ALREADY_RECORDED = 'ONBOARDING_ALREADY_RECORDED';

    /**
     * Named `$errorCode` rather than `$code` because `Exception::$code` already
     * exists, is an int, and is not readonly — PHP refuses the redeclaration
     * outright. Worth the extra word: the alternative is reusing the built-in
     * int, and an error envelope carrying `"code": 0` is worse than a longer
     * property name.
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function staleToken(): self
    {
        return new self(
            'This item changed while you were working on it. Reload and try again.',
            self::STALE_TOKEN,
        );
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(
            'This request key has already been used for a different request.',
            self::IDEMPOTENCY_KEY_REUSED,
        );
    }

    public static function alreadyRecorded(string $what): self
    {
        return new self(
            sprintf('Your %s has already been recorded and cannot be changed.', $what),
            self::ALREADY_RECORDED,
        );
    }
}
