<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Service;

use TriCab\Core\LogNote\Application\DTO\LogNoteSummaryResult;
use TriCab\Core\LogNote\Contract\LogNoteSummariser;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;

final class SummariseLogNoteService
{
    public function __construct(
        private readonly LogNoteSummariser $summariser,
    ) {
    }

    public function execute(string $rawText): LogNoteSummaryResult
    {
        $body = LogNoteBody::fromString($rawText);
        $summarised = LogNoteBody::fromString($this->summariser->summarise($body->toString()));

        return new LogNoteSummaryResult(
            summarisedBody: $summarised->toString(),
            provider: $this->summariser->providerName(),
        );
    }
}
