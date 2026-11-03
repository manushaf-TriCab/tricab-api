<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

final readonly class QuotegenAgentResult
{
    /**
     * @param list<array{kind: string, name: string, summary: string}> $events
     * @param list<string> $changedLineIds
     */
    public function __construct(
        public string $text,
        public array $events,
        public array $changedLineIds,
        public ?int $proposedSyncRevision,
        public bool $containsPricing,
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }
}
