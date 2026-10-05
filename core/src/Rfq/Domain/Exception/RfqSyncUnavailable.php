<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/**
 * Sending to 4js is not available, and nothing was sent. Maps to 503; never
 * pretends success (parity plan E7.3).
 *
 *  - `disabled`: the owner's switch (`RFQ_SYNC_ENABLED`) is not exactly `true`;
 *  - `not_connected`: the switch is on but no adapter is wired.
 */
final class RfqSyncUnavailable extends RuntimeException
{
    public const DISABLED = 'disabled';
    public const NOT_CONNECTED = 'not_connected';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'Sending quotes to 4js is switched off on this server. Nothing was sent.');
    }

    public static function notConnected(string $note): self
    {
        return new self(self::NOT_CONNECTED, $note . ' Nothing was sent.');
    }
}
