<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

final class InMemoryPlatformSettingsRepository implements PlatformSettingsRepository
{
    private int $version = 0;
    /** @var array<string,mixed> */
    private array $overrides = [];
    /** @var array<string,mixed>|null */
    private ?array $defaults = null;
    /** @var list<array<string,mixed>> */
    private array $events = [];

    public function current(): array
    {
        return ['version' => $this->version, 'overrides' => $this->overrides, 'defaults' => $this->defaults ?? []];
    }

    public function history(): array
    {
        return array_reverse($this->events);
    }

    public function replace(int $expectedVersion, array $overrides, array $defaults, int $actorUserId, string $operation): bool
    {
        if ($this->version !== $expectedVersion) {
            return false;
        }
        $this->defaults ??= $defaults;
        $this->version++;
        $this->overrides = $overrides;
        $this->events[] = [
            'version' => $this->version,
            'changedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            'actorUserId' => $actorUserId,
            'operation' => $operation,
            'overrides' => $overrides,
        ];
        return true;
    }
}
