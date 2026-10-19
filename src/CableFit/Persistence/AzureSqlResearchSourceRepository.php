<?php

declare(strict_types=1);

namespace TriCab\Api\CableFit\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\CableFit\Contract\ResearchSourceRepository;
use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;

/**
 * `[cablefit].[research_sources]` on SQL Server (`create-cablefit-schema.sql`). Public ids go out lowercase.
 */
final class AzureSqlResearchSourceRepository implements ResearchSourceRepository
{
    private const COLUMNS = <<<'SQL'
        CONVERT(NVARCHAR(36), s.[public_id]) AS [public_id],
        s.[domain], s.[name], s.[category], s.[enabled], s.[notes], s.[created_at], s.[updated_at]
SQL;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [cablefit].[research_sources] s ORDER BY s.[category], s.[domain]',
        );

        return array_map(fn (array $row): ResearchSource => $this->source($row), $rows);
    }

    public function findByPublicId(string $publicId): ?ResearchSource
    {
        if (preg_match(self::GUID, $publicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [cablefit].[research_sources] s WHERE s.[public_id] = :id',
            ['id' => $publicId],
        )[0] ?? null;

        return $row === null ? null : $this->source($row);
    }

    public function findByDomain(string $domain): ?ResearchSource
    {
        $row = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [cablefit].[research_sources] s WHERE s.[domain] = :domain',
            ['domain' => $domain],
        )[0] ?? null;

        return $row === null ? null : $this->source($row);
    }

    public function add(ResearchSource $source, int $userId): void
    {
        $inserted = $this->connection->fetchAll(
            'INSERT INTO [cablefit].[research_sources] (
                [public_id], [domain], [name], [category], [enabled], [notes],
                [created_by_user_id], [created_at], [updated_by_user_id], [updated_at])
             OUTPUT inserted.[source_id]
             SELECT :publicId, :domain, :name, :category, :enabled, :notes, :createdBy, :createdAt, :updatedBy, :updatedAt
             WHERE NOT EXISTS (SELECT 1 FROM [cablefit].[research_sources] WHERE [domain] = :domainCheck)',
            [
                'publicId' => $source->publicId,
                'domain' => $source->domain,
                'name' => $source->name,
                'category' => $source->category,
                'enabled' => $source->enabled ? 1 : 0,
                'notes' => $source->notes,
                'createdBy' => $userId,
                'createdAt' => AzureSqlValueMapper::formatDateTime($source->createdAt),
                'updatedBy' => $userId,
                'updatedAt' => AzureSqlValueMapper::formatDateTime($source->updatedAt),
                'domainCheck' => $source->domain,
            ],
        );
        if ($inserted === []) {
            throw new ResearchSourceExists('A source for ' . $source->domain . ' already exists.');
        }
    }

    public function save(ResearchSource $source, int $userId): void
    {
        if (preg_match(self::GUID, $source->publicId) !== 1) {
            return;
        }
        $this->connection->execute(
            'UPDATE [cablefit].[research_sources]
             SET [name] = :name, [category] = :category, [enabled] = :enabled, [notes] = :notes,
                 [updated_by_user_id] = :userId, [updated_at] = :at
             WHERE [public_id] = :id',
            [
                'name' => $source->name,
                'category' => $source->category,
                'enabled' => $source->enabled ? 1 : 0,
                'notes' => $source->notes,
                'userId' => $userId,
                'at' => AzureSqlValueMapper::formatDateTime($source->updatedAt),
                'id' => $source->publicId,
            ],
        );
    }

    public function enabledDomains(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT s.[domain] FROM [cablefit].[research_sources] s WHERE s.[enabled] = 1 ORDER BY s.[domain]',
        );

        return array_map(
            static fn (array $row): string => strtolower(AzureSqlValueMapper::stringValue($row['domain'] ?? null)),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function source(array $row): ResearchSource
    {
        return new ResearchSource(
            strtolower(AzureSqlValueMapper::stringValue($row['public_id'] ?? null)),
            AzureSqlValueMapper::stringValue($row['domain'] ?? null),
            AzureSqlValueMapper::stringValue($row['name'] ?? null),
            AzureSqlValueMapper::stringValue($row['category'] ?? null),
            AzureSqlValueMapper::boolValue($row['enabled'] ?? null),
            AzureSqlValueMapper::nullableString($row['notes'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['created_at'] ?? null, 'created_at'),
            AzureSqlValueMapper::requireDateTime($row['updated_at'] ?? null, 'updated_at'),
        );
    }
}
