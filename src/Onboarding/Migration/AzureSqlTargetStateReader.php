<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;
use TriCab\Core\Onboarding\Migration\Target\TargetState;

/**
 * Reads what already exists on the platform for the people in a snapshot
 * (Phase 7.3). Read-only.
 *
 * Accounts are found the way 4.1 finds them — by the primary address or any
 * active address a person holds ({@see OnboardingAccountDirectory}) — and
 * never by name.
 */
final readonly class AzureSqlTargetStateReader
{
    public function __construct(
        private SqlConnection $connection,
        private OnboardingAccountDirectory $accounts,
    ) {
    }

    public function read(SourceSnapshot $snapshot, TargetCatalogue $catalogue): TargetState
    {
        $accounts = [];
        foreach ($snapshot->rows('trainees') as $trainee) {
            $email = strtolower(trim(is_string($trainee['email']) ? $trainee['email'] : ''));
            try {
                $userId = $this->accounts->findUserIdByEmail(EmailAddress::fromString($email));
            } catch (InvalidIdentityValue) {
                continue;
            }
            if ($userId !== null) {
                $accounts[$email] = ['userId' => $userId, 'canSignIn' => $this->accounts->canSignIn($userId)];
            }
        }
        $userIds = array_values(array_unique(array_column($accounts, 'userId')));

        return new TargetState(
            $accounts,
            $this->profiles($userIds),
            $this->liveAssignments($userIds),
            $this->acknowledgedPolicies($userIds, $catalogue),
            $this->migrated(),
        );
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, array{publicId: string, departed: bool}>
     */
    private function profiles(array $userIds): array
    {
        $profiles = [];
        foreach ($this->inChunks(
            'SELECT [UserId], LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId], [DepartedAtUtc] FROM [onboarding].[TraineeProfiles] WHERE [UserId] IN (%s)',
            $userIds,
        ) as $row) {
            $profiles[AzureSqlValueMapper::intValue($row['UserId'] ?? 0)] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'departed' => ($row['DepartedAtUtc'] ?? null) !== null,
            ];
        }

        return $profiles;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<string>>
     */
    private function liveAssignments(array $userIds): array
    {
        $live = [];
        foreach ($this->inChunks(<<<'SQL'
            SELECT a.[UserId], a.[ItemType], COALESCE(tm.[Slug], p.[Slug], fd.[Slug]) AS [Slug]
            FROM [onboarding].[Assignments] a
            LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            WHERE a.[WithdrawnAtUtc] IS NULL AND a.[UserId] IN (%s)
            SQL, $userIds) as $row) {
            $live[AzureSqlValueMapper::intValue($row['UserId'] ?? 0)][] = AzureSqlValueMapper::stringValue($row['ItemType'] ?? '') . ':' . AzureSqlValueMapper::stringValue($row['Slug'] ?? '');
        }

        return $live;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<string>>
     */
    private function acknowledgedPolicies(array $userIds, TargetCatalogue $catalogue): array
    {
        $liveVersion = [];
        foreach ($catalogue->policies as $slug => $policy) {
            $liveVersion[$policy['versionId']] = $slug;
        }
        $acknowledged = [];
        foreach ($this->inChunks('SELECT [UserId], [PolicyVersionId] FROM [onboarding].[PolicyAcknowledgements] WHERE [UserId] IN (%s)', $userIds) as $row) {
            $slug = $liveVersion[AzureSqlValueMapper::intValue($row['PolicyVersionId'] ?? 0)] ?? null;
            if ($slug !== null) {
                $acknowledged[AzureSqlValueMapper::intValue($row['UserId'] ?? 0)][] = $slug;
            }
        }

        return $acknowledged;
    }

    /** @return list<string> */
    private function migrated(): array
    {
        $exists = $this->connection->fetchAll("SELECT OBJECT_ID(N'[migration].[OnboardingMigrationRows]', N'U') AS [Id]");
        if (($exists[0]['Id'] ?? null) === null) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT [SourceTable], [SourceId] FROM [migration].[OnboardingMigrationRows] WHERE [SourceSystem] = :system AND [RolledBackAtUtc] IS NULL',
            [':system' => SourceSnapshotSchema::SOURCE_SYSTEM],
        );

        return array_map(
            static fn (array $r): string => AzureSqlValueMapper::stringValue($r['SourceTable'] ?? '') . ':' . AzureSqlValueMapper::intValue($r['SourceId'] ?? 0),
            $rows,
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function inChunks(string $sqlWithIn, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $id) {
                $params[':id' . $i] = $id;
            }
            array_push($rows, ...$this->connection->fetchAll(sprintf($sqlWithIn, implode(', ', array_keys($params))), $params));
        }

        return $rows;
    }
}
