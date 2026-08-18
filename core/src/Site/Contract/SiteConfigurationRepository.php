<?php

declare(strict_types=1);

namespace TriCab\Core\Site\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface SiteConfigurationRepository
{
    public function getJson(string $configKey): ?string;

    public function upsertJson(string $configKey, string $configJson, ?UserId $actorUserId): void;
}
