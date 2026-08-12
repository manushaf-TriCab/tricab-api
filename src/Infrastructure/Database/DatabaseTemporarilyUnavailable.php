<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use Throwable;

/**
 * Connection could not be established after a bounded serverless-resume retry.
 * Distinct from permanent DatabaseUnavailable / DatabaseAuthFailed.
 */
final class DatabaseTemporarilyUnavailable extends DatabaseUnavailable
{
    public function __construct(
        string $message = 'The database is temporarily unavailable.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly string $retryReason = 'HYT00',
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function afterConnectTimeout(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            'The database is temporarily unavailable.',
            0,
            $previous,
            $reason,
        );
    }
}
