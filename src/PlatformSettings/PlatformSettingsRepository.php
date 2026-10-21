<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

interface PlatformSettingsRepository
{
    /** @return array{version:int,overrides:array<string,mixed>,defaults?:array<string,mixed>} */
    public function current(): array;

    /** @return list<array<string,mixed>> */
    public function history(): array;

    /** @param array<string,mixed> $overrides
     * @param array<string,mixed> $defaults
     */
    public function replace(int $expectedVersion, array $overrides, array $defaults, int $actorUserId, string $operation): bool;
}
