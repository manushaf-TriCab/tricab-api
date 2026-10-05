<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Sync;

/**
 * What the 4js quote header request came to (source `execute_quote_push`, QOTHDR).
 *
 * Three answers, and the difference is the whole point of the attempt ledger:
 *
 *  - `accepted`: 4js created, or versioned, the quote and returned its number;
 *  - `rejected`: 4js answered no. **Certain**: no quote was made, a corrected retry is safe;
 *  - `unknown`: the request did not complete (a timeout, a dropped connection, an
 *    adapter that failed). 4js may or may not hold a quote. **Never** read this as a rejection.
 */
final class GatewayHeaderResult
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $outcome,
        public readonly string $refno,
        public readonly string $refver,
        public readonly string $message,
    ) {
    }

    public static function accepted(string $refno, string $refver): self
    {
        return new self(self::ACCEPTED, $refno, $refver, '');
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, '', '', $message);
    }

    public static function unknown(string $message): self
    {
        return new self(self::UNKNOWN, '', '', $message);
    }
}
