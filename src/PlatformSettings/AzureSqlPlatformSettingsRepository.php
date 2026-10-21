<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use RuntimeException;

final class AzureSqlPlatformSettingsRepository implements PlatformSettingsRepository
{
    public function __construct(private readonly SqlConnection $connection, private readonly Settings $settings) {}

    public function current(): array
    {
        try {
            $rows = $this->connection->fetchAll('SELECT [Version], [OverridesJson], [DefaultsJson] FROM [site].[PlatformSettings] WHERE [Environment] = :environment', [':environment' => $this->settings->appEnv]);
        } catch (\Throwable $exception) {
            if (self::isMissingTable($exception, 'PlatformSettings')) {
                return ['version' => 0, 'overrides' => []];
            }
            throw $exception;
        }
        if ($rows === []) {
            return ['version' => 0, 'overrides' => []];
        }
        return [
            'version' => AzureSqlValueMapper::intValue($rows[0]['Version'] ?? null),
            'overrides' => self::decodeObject($rows[0]['OverridesJson'] ?? null),
            'defaults' => self::decodeObject($rows[0]['DefaultsJson'] ?? null),
        ];
    }

    public function history(): array
    {
        try {
            $rows = $this->connection->fetchAll('SELECT [Version], [ChangedAtUtc], [ActorUserId], [Operation], [OverridesJson] FROM [site].[PlatformSettingsHistory] WHERE [Environment] = :environment ORDER BY [Version] DESC', [':environment' => $this->settings->appEnv]);
        } catch (\Throwable $exception) {
            if (self::isMissingTable($exception, 'PlatformSettingsHistory')) {
                return [];
            }
            throw $exception;
        }
        return array_map(static fn (array $row): array => [
            'version' => AzureSqlValueMapper::intValue($row['Version'] ?? null),
            'changedAtUtc' => AzureSqlValueMapper::requireDateTime($row['ChangedAtUtc'] ?? null, 'ChangedAtUtc')->format(DATE_ATOM),
            'actorUserId' => AzureSqlValueMapper::intValue($row['ActorUserId'] ?? null),
            'operation' => AzureSqlValueMapper::stringValue($row['Operation'] ?? null),
            'overrides' => self::decodeObject($row['OverridesJson'] ?? null),
        ], $rows);
    }

    /** @return array<string,mixed> */
    private static function decodeObject(mixed $json): array
    {
        if (!is_string($json)) {
            throw new RuntimeException('Settings JSON is missing.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('Settings JSON must be an object.');
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Settings JSON has an invalid key.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function isMissingTable(\Throwable $exception, string $table): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'invalid object name') && str_contains($message, strtolower($table));
    }

    public function replace(int $expectedVersion, array $overrides, array $defaults, int $actorUserId, string $operation): bool
    {
        $json = json_encode($overrides, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $defaultsJson = json_encode($defaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $started = !$this->connection->inTransaction();
        if ($started) {
            $this->connection->beginTransaction();
        }
        try {
            // UPDLOCK + HOLDLOCK serializes writers, including the initial row insert.
            $rows = $this->connection->fetchAll('SELECT [Version] FROM [site].[PlatformSettings] WITH (UPDLOCK, HOLDLOCK) WHERE [Environment] = :environment', [':environment' => $this->settings->appEnv]);
            $actual = $rows === [] ? 0 : AzureSqlValueMapper::intValue($rows[0]['Version'] ?? null);
            if ($actual !== $expectedVersion) {
                if ($started) {
                    $this->connection->rollBack();
                }
                return false;
            }
            $params = [':environment' => $this->settings->appEnv, ':json' => $json, ':defaults' => $defaultsJson, ':actor' => $actorUserId, ':version' => $actual + 1];
            if ($rows === []) {
                $this->connection->execute('INSERT INTO [site].[PlatformSettings] ([Environment], [Version], [OverridesJson], [DefaultsJson], [UpdatedByUserId]) VALUES (:environment, :version, :json, :defaults, :actor)', $params);
            } else {
                $this->connection->execute('UPDATE [site].[PlatformSettings] SET [Version] = :version, [OverridesJson] = :json, [UpdatedByUserId] = :actor, [UpdatedAtUtc] = SYSUTCDATETIME() WHERE [Environment] = :environment', [':environment' => $this->settings->appEnv, ':version' => $actual + 1, ':json' => $json, ':actor' => $actorUserId]);
            }
            $this->connection->execute('INSERT INTO [site].[PlatformSettingsHistory] ([Environment], [Version], [ActorUserId], [Operation], [OverridesJson]) VALUES (:environment, :version, :actor, :operation, :json)', [':environment' => $this->settings->appEnv, ':version' => $actual + 1, ':actor' => $actorUserId, ':operation' => $operation, ':json' => $json]);
            if ($started) {
                $this->connection->commit();
            }
            return true;
        } catch (\Throwable $exception) {
            if ($started && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}
