<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Sync;

/** One QOTITEM: accepted by 4js, or not, with 4js's own words (or the transport's) when not. */
final class GatewayLineResult
{
    private function __construct(public readonly bool $accepted, public readonly string $message)
    {
    }

    public static function accepted(): self
    {
        return new self(true, '');
    }

    public static function rejected(string $message): self
    {
        return new self(false, $message);
    }
}
