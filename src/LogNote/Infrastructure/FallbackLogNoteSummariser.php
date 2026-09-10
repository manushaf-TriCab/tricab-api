<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Infrastructure;

use Throwable;
use TriCab\Core\LogNote\Contract\LogNoteSummariser;

final class FallbackLogNoteSummariser implements LogNoteSummariser
{
    public function __construct(
        private readonly LogNoteSummariser $primary,
        private readonly LogNoteSummariser $fallback,
    ) {
    }

    public function summarise(string $rawText): string
    {
        try {
            return $this->primary->summarise($rawText);
        } catch (Throwable) {
            return $this->fallback->summarise($rawText);
        }
    }

    public function providerName(): string
    {
        return $this->primary->providerName();
    }
}
