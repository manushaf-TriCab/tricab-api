<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/** What the repository needs to open a draft; it assigns the number, ids and version. */
final readonly class NewNprRequest
{
    /**
     * @param array<string, mixed>       $answers
     * @param list<array<string, mixed>> $variants
     */
    public function __construct(
        public int $requesterUserId,
        public string $requesterEmail,
        public string $requesterName,
        public string $requesterBranch,
        public string $title,
        public string $customerName,
        public string $urgencyLevel,
        public array $answers,
        public array $variants,
    ) {
    }
}
