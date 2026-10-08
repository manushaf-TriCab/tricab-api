<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

/** What the runner did about one job in one pass. */
final readonly class FinanceJobOutcome
{
    public const RAN = 'ran';
    public const FAILED = 'failed';
    public const NOT_DUE = 'not_due';
    public const ALREADY_CLAIMED = 'already_claimed';
    public const CLAIM_ERROR = 'claim_error';

    public function __construct(
        public string $job,
        public string $status,
        public ?string $slot = null,
        public ?string $error = null,
    ) {
    }
}
