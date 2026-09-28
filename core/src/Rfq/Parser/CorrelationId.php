<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * One id for one piece of work, across PHP, the parser, workers and model
 * providers, so a failure can be followed without logging content.
 *
 * Accepted from an inbound `X-Correlation-Id` only when it is short and plain;
 * anything else is replaced, never echoed — a client-chosen value ends up in
 * log lines, and log injection starts with "we just copied the header".
 */
final class CorrelationId
{
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromHeaderOrGenerate(?string $header): self
    {
        $header = $header === null ? '' : trim($header);

        return preg_match(self::PATTERN, $header) === 1 ? new self($header) : self::generate();
    }

    public function toString(): string
    {
        return $this->value;
    }
}
