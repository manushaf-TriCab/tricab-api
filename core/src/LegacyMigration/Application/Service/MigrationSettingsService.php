<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class MigrationSettingsService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->engine->settings();
    }

    public function update(ActorContext $actor, string $key, string $value): void
    {
        $this->engine->updateSetting($actor, $key, $value);
    }
}
