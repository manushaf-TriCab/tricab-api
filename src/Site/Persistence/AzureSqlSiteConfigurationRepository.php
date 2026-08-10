<?php

declare(strict_types=1);

namespace TriCab\Api\Site\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Site\Contract\SiteConfigurationRepository;

final class AzureSqlSiteConfigurationRepository implements SiteConfigurationRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function getJson(string $configKey): ?string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [ConfigJson]
FROM [site].[SiteConfigurations]
WHERE [ConfigKey] = :configKey
SQL,
            [':configKey' => $configKey],
        );
        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::nullableString($rows[0]['ConfigJson'] ?? null);
    }

    public function upsertJson(string $configKey, string $configJson, ?UserId $actorUserId): void
    {
        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT [SiteConfigurationId]
FROM [site].[SiteConfigurations]
WHERE [ConfigKey] = :configKey
SQL,
            [':configKey' => $configKey],
        );

        if ($existing === []) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [site].[SiteConfigurations] ([ConfigKey], [ConfigJson], [UpdatedByUserId])
VALUES (:configKey, :configJson, :updatedBy)
SQL,
                [
                    ':configKey' => $configKey,
                    ':configJson' => $configJson,
                    ':updatedBy' => $actorUserId?->toInt(),
                ],
            );

            return;
        }

        $this->connection->execute(
            <<<'SQL'
UPDATE [site].[SiteConfigurations]
SET
    [ConfigJson] = :configJson,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedBy
WHERE [ConfigKey] = :configKey
SQL,
            [
                ':configKey' => $configKey,
                ':configJson' => $configJson,
                ':updatedBy' => $actorUserId?->toInt(),
            ],
        );
    }
}
