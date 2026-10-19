<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/** A save of a draft's answers, with the person making it. */
final readonly class NprDraftChange
{
    /**
     * @param array<string, mixed>       $answers
     * @param list<array<string, mixed>> $variants
     */
    public function __construct(
        public int $actorUserId,
        public string $actorEmail,
        public string $actorName,
        public string $title,
        public string $customerName,
        public string $urgencyLevel,
        public array $answers,
        public array $variants,
        public ?string $changeSummary,
    ) {
    }
}
