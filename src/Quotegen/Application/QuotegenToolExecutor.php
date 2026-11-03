<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

interface QuotegenToolExecutor
{
    /** @return list<array<string, mixed>> Messages API tool definitions */
    public function definitions(): array;

    /**
     * Runs one tool for the turn's server-resolved actor and RFQ.
     *
     * @param array<string, mixed> $input
     */
    public function execute(QuotegenTurnContext $context, string $toolUseId, string $name, array $input): QuotegenToolResult;
}
