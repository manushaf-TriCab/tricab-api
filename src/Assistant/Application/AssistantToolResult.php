<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>|null $spec The requirement this call put to
     *        the finder or the code search, in `AssistantReplySpec` shape.
     */
    public function __construct(
        public array $data,
        public array $cards = [],
        public ?array $spec = null,
    ) {
    }
}
