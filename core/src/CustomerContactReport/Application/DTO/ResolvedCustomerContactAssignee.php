<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class ResolvedCustomerContactAssignee
{
    public function __construct(
        public int $userId,
        public string $login,
        public string $displayName,
        public string $email,
    ) {
    }
}
