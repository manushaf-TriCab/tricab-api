<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Migration;

use RuntimeException;

/**
 * The migration will not go on, and says why with a stable code (Phase 7).
 *
 * Every step refuses rather than guesses: a snapshot outside the allow-list, a
 * stale preview fingerprint, a non-synthetic source while production is
 * disabled, a rollback that would remove somebody's later work.
 */
final class OnboardingMigrationRefused extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($reason . ': ' . $message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
