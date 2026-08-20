<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface Verifier
{
    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array{ok: bool, message: string, checked: int}
     */
    public function verify(array $snapshots): array;
}
