<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

final readonly class QuotegenToolResult
{
    /**
     * @param array<string, mixed> $data what the model is shown
     * @param list<string> $changedLineIds
     */
    public function __construct(
        public bool $ok,
        public array $data,
        public string $summary,
        public array $changedLineIds = [],
        public ?int $newRevision = null,
        public ?int $proposedSyncRevision = null,
        public bool $containsPricing = false,
        public bool $replayed = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function failure(string $error, string $summary, array $data = []): self
    {
        return new self(false, ['error' => $error, ...$data], $summary);
    }
}
