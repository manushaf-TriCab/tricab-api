<?php

declare(strict_types=1);

namespace TriCab\Api\Site\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Site\Contract\SiteConfigurationRepository;

final class InMemorySiteConfigurationRepository implements SiteConfigurationRepository
{
    /** @var array<string, string> */
    private array $configs = [];

    public function getJson(string $configKey): ?string
    {
        return $this->configs[$configKey] ?? null;
    }

    public function upsertJson(string $configKey, string $configJson, ?UserId $actorUserId): void
    {
        unset($actorUserId);
        $this->configs[$configKey] = $configJson;
    }
}
