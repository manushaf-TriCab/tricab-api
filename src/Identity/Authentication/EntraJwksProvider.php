<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

interface EntraJwksProvider
{
    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(string $tenantId): array;
}
