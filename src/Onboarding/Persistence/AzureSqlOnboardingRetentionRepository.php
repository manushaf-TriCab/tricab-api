<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Contract\OnboardingRetentionRepository;

final class AzureSqlOnboardingRetentionRepository implements OnboardingRetentionRepository
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function listClasses(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ClassCode], [ClassName], [RetentionDays], CONVERT(VARCHAR(33), [DecidedAtUtc], 127) AS [DecidedAtUtc]
             FROM [onboarding].[RetentionClasses]
             ORDER BY [RetentionClassId]',
        );

        return array_map(static fn (array $row): array => [
            'classCode' => AzureSqlValueMapper::stringValue($row['ClassCode'] ?? ''),
            'className' => AzureSqlValueMapper::stringValue($row['ClassName'] ?? ''),
            'retentionDays' => AzureSqlValueMapper::nullableInt($row['RetentionDays'] ?? null),
            'decidedAtUtc' => AzureSqlValueMapper::nullableString($row['DecidedAtUtc'] ?? null),
        ], $rows);
    }
}
