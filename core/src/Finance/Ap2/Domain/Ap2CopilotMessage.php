<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One stored Copilot message: what the person asked (`user`) or what the assistant answered (`assistant`). */
final readonly class Ap2CopilotMessage
{
    /**
     * @param 'user'|'assistant' $role
     * @param list<array<string, mixed>> $proposals what the assistant offered (empty for a `user` message)
     */
    public function __construct(
        public string $id,
        public int $userId,
        public ?string $mailId,
        public ?string $supplierId,
        public string $role,
        public string $content,
        public array $proposals,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
