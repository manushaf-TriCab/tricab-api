<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\DTO;

final readonly class LogNoteSummaryResult
{
    public function __construct(
        public string $summarisedBody,
        public string $provider,
    ) {
    }
}
