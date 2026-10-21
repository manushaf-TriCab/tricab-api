<?php

declare(strict_types=1);

namespace TriCab\Api\CableFit\Persistence;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\CableFit\Contract\CableFitCandidateRepository;
use TriCab\Core\CableFit\Domain\CableFitCandidate;
use TriCab\Core\CableFit\Domain\CandidateListPage;
use TriCab\Core\CableFit\Domain\CandidateListRow;
use TriCab\Core\CableFit\Domain\CandidateSearch;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;

/**
 * `[cablefit].[candidates]` and `[cablefit].[candidate_revisions]` on SQL Server
 * (`create-cablefit-schema.sql`).
 *
 * The version is `current_revision_number`. A save moves it with
 * `UPDATE ... WHERE current_revision_number = @expected`; no row updated means
 * someone else saved first. The update and the revision insert share one
 * transaction, so a revision never exists without the candidate pointing at it.
 *
 * Public ids go out lowercase, whatever case the server returns. A deleted
 * candidate (`deleted_at` set, alter-cablefit-candidate-delete.sql) is left out
 * of every read.
 */
final class AzureSqlCableFitCandidateRepository implements CableFitCandidateRepository
{
    use AzureSqlTransaction;

    private const CANDIDATE_COLUMNS = <<<'SQL'
        CONVERT(NVARCHAR(36), c.[public_id]) AS [public_id],
        c.[npr_request_public_id],
        c.[npr_variant_id],
        c.[candidate_name],
        c.[current_revision_number],
        c.[created_at],
        c.[updated_at],
        c.[created_by_user_id],
        c.[updated_by_user_id]
SQL;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Every character LIKE treats as a wildcard, escaped for ESCAPE N'~'. A tilde, not a backslash:
     * PDO's placeholder scanner reads N'\' as an unterminated string (see AzureSqlProductCodeSearchRepository).
     */
    private const LIKE_ESCAPE = ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~['];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByNprRequest(string $nprRequestPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::CANDIDATE_COLUMNS . '
             FROM [cablefit].[candidates] c
             WHERE c.[npr_request_public_id] = :request AND c.[deleted_at] IS NULL
             ORDER BY c.[updated_at] DESC, c.[candidate_id] DESC',
            ['request' => $nprRequestPublicId],
        );

        return array_map(fn (array $row): CableFitCandidate => $this->candidate($row), $rows);
    }

    public function search(CandidateSearch $search): CandidateListPage
    {
        $where = ' WHERE c.[deleted_at] IS NULL';
        $params = [];
        if ($search->text !== null) {
            $where .= " AND (c.[candidate_name] LIKE :text ESCAPE N'~'";
            $params['text'] = '%' . strtr($search->text, self::LIKE_ESCAPE) . '%';
            $placeholders = [];
            foreach ($search->nprRequestPublicIds as $i => $id) {
                $placeholders[] = ':npr' . $i;
                $params['npr' . $i] = $id;
            }
            if ($placeholders !== []) {
                $where .= ' OR c.[npr_request_public_id] IN (' . implode(', ', $placeholders) . ')';
            }
            $where .= ')';
        }

        $total = AzureSqlValueMapper::intValue(
            $this->connection->fetchAll('SELECT COUNT(*) AS [total] FROM [cablefit].[candidates] c' . $where, $params)[0]['total'] ?? 0,
        );
        if ($total === 0) {
            return new CandidateListPage([], 0);
        }

        $rows = $this->connection->fetchAll(
            'SELECT ' . self::CANDIDATE_COLUMNS . ",
                    COALESCE(NULLIF(cu.[DisplayName], N''), cu.[EmailAddress]) AS [created_by_name],
                    cu.[EmailAddress] AS [created_by_email],
                    COALESCE(NULLIF(uu.[DisplayName], N''), uu.[EmailAddress]) AS [updated_by_name],
                    uu.[EmailAddress] AS [updated_by_email],
                    r.[npr_revision_number], r.[stale]
             FROM [cablefit].[candidates] c
             LEFT JOIN [cablefit].[candidate_revisions] r
               ON r.[candidate_id] = c.[candidate_id] AND r.[revision_number] = c.[current_revision_number]
             LEFT JOIN [identity].[Users] cu ON cu.[UserId] = c.[created_by_user_id]
             LEFT JOIN [identity].[Users] uu ON uu.[UserId] = c.[updated_by_user_id]"
            . $where . '
             ORDER BY c.[updated_at] DESC, c.[candidate_id] DESC
             OFFSET ' . max(0, $search->offset) . ' ROWS FETCH NEXT ' . max(1, $search->limit) . ' ROWS ONLY',
            $params,
        );

        return new CandidateListPage(
            array_map(fn (array $row): CandidateListRow => new CandidateListRow(
                $this->candidate($row),
                AzureSqlValueMapper::nullableString($row['created_by_name'] ?? null),
                AzureSqlValueMapper::nullableString($row['created_by_email'] ?? null),
                AzureSqlValueMapper::nullableString($row['updated_by_name'] ?? null),
                AzureSqlValueMapper::nullableString($row['updated_by_email'] ?? null),
                AzureSqlValueMapper::nullableInt($row['npr_revision_number'] ?? null),
                AzureSqlValueMapper::boolValue($row['stale'] ?? null),
            ), $rows),
            $total,
        );
    }

    public function linkedNprRequestPublicIds(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT [npr_request_public_id] FROM [cablefit].[candidates]
             WHERE [npr_request_public_id] IS NOT NULL AND [deleted_at] IS NULL',
            [],
        );

        return array_map(static fn (array $row): string => AzureSqlValueMapper::stringValue($row['npr_request_public_id'] ?? null), $rows);
    }

    public function findByPublicId(string $candidatePublicId): ?CableFitCandidate
    {
        if (preg_match(self::GUID, $candidatePublicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(
            'SELECT ' . self::CANDIDATE_COLUMNS . '
             FROM [cablefit].[candidates] c
             WHERE c.[public_id] = :id AND c.[deleted_at] IS NULL',
            ['id' => $candidatePublicId],
        )[0] ?? null;

        return $row === null ? null : $this->candidate($row);
    }

    public function latestRevision(string $candidatePublicId): ?CandidateRevision
    {
        if (preg_match(self::GUID, $candidatePublicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(
            'SELECT TOP (1)
                    CONVERT(NVARCHAR(36), c.[public_id]) AS [public_id],
                    r.[revision_number], r.[design_json], r.[mapping_version], r.[stale],
                    r.[npr_revision_number], r.[created_at], r.[saved_by_user_id]
             FROM [cablefit].[candidates] c
             JOIN [cablefit].[candidate_revisions] r
               ON r.[candidate_id] = c.[candidate_id] AND r.[revision_number] = c.[current_revision_number]
             WHERE c.[public_id] = :id AND c.[deleted_at] IS NULL',
            ['id' => $candidatePublicId],
        )[0] ?? null;

        return $row === null ? null : $this->revision($row);
    }

    public function create(CableFitCandidate $candidate, CandidateRevision $firstRevision): void
    {
        $this->runInTransaction($this->connection, function () use ($candidate, $firstRevision): void {
            $inserted = $this->connection->fetchAll(
                'INSERT INTO [cablefit].[candidates] (
                    [public_id], [npr_request_public_id], [npr_variant_id], [candidate_name],
                    [current_revision_number], [created_by_user_id], [created_at], [updated_by_user_id], [updated_at])
                 OUTPUT inserted.[candidate_id]
                 VALUES (:publicId, :request, :variant, :name, :version, :createdBy, :createdAt, :updatedBy, :updatedAt)',
                [
                    'publicId' => $candidate->publicId,
                    'request' => $candidate->nprRequestPublicId,
                    'variant' => $candidate->nprVariantId,
                    'name' => $candidate->name,
                    'version' => $candidate->version,
                    'createdBy' => $candidate->createdByUserId,
                    'createdAt' => AzureSqlValueMapper::formatDateTime($candidate->createdAt),
                    'updatedBy' => $candidate->updatedByUserId,
                    'updatedAt' => AzureSqlValueMapper::formatDateTime($candidate->updatedAt),
                ],
            );
            $this->insertRevision(AzureSqlValueMapper::intValue($inserted[0]['candidate_id'] ?? null), $firstRevision);
        });
    }

    public function appendRevision(
        string $candidatePublicId,
        int $expectedVersion,
        CandidateRevision $revision,
        int $userId,
    ): CableFitCandidate {
        $this->runInTransaction($this->connection, function () use ($candidatePublicId, $expectedVersion, $revision, $userId): void {
            if ($revision->revisionNumber !== $expectedVersion + 1) {
                throw new RuntimeException('A new revision must be numbered one above the expected version.');
            }
            if (preg_match(self::GUID, $candidatePublicId) !== 1) {
                throw new RuntimeException('Unknown candidate ' . $candidatePublicId);
            }

            $updated = $this->connection->fetchAll(
                'UPDATE [cablefit].[candidates]
                 SET [current_revision_number] = :newVersion, [updated_at] = :at, [updated_by_user_id] = :userId
                 OUTPUT inserted.[candidate_id]
                 WHERE [public_id] = :id AND [current_revision_number] = :expected AND [deleted_at] IS NULL',
                [
                    'newVersion' => $revision->revisionNumber,
                    'at' => AzureSqlValueMapper::formatDateTime($revision->createdAt),
                    'userId' => $userId,
                    'id' => $candidatePublicId,
                    'expected' => $expectedVersion,
                ],
            );
            if ($updated === []) {
                $current = $this->findByPublicId($candidatePublicId);
                if ($current === null) {
                    throw new RuntimeException('Unknown candidate ' . $candidatePublicId);
                }

                throw new CandidateVersionConflict($candidatePublicId, $expectedVersion, $current->version);
            }

            $this->insertRevision(AzureSqlValueMapper::intValue($updated[0]['candidate_id'] ?? null), $revision);
        });

        return $this->findByPublicId($candidatePublicId)
            ?? throw new RuntimeException('Unknown candidate ' . $candidatePublicId);
    }

    public function markRevisionStale(string $candidatePublicId, int $revisionNumber, bool $stale): void
    {
        if (preg_match(self::GUID, $candidatePublicId) !== 1) {
            return;
        }
        $this->connection->execute(
            'UPDATE r SET r.[stale] = :stale
             FROM [cablefit].[candidate_revisions] r
             JOIN [cablefit].[candidates] c ON c.[candidate_id] = r.[candidate_id]
             WHERE c.[public_id] = :id AND r.[revision_number] = :number',
            ['stale' => $stale ? 1 : 0, 'id' => $candidatePublicId, 'number' => $revisionNumber],
        );
    }

    public function markDeleted(string $candidatePublicId, int $userId, DateTimeImmutable $at): bool
    {
        if (preg_match(self::GUID, $candidatePublicId) !== 1) {
            return false;
        }

        return $this->connection->fetchAll(
            'UPDATE [cablefit].[candidates]
             SET [deleted_at] = :at, [deleted_by_user_id] = :userId
             OUTPUT inserted.[candidate_id]
             WHERE [public_id] = :id AND [deleted_at] IS NULL',
            ['at' => AzureSqlValueMapper::formatDateTime($at), 'userId' => $userId, 'id' => $candidatePublicId],
        ) !== [];
    }

    private function insertRevision(int $candidateId, CandidateRevision $revision): void
    {
        $this->connection->execute(
            'INSERT INTO [cablefit].[candidate_revisions] (
                [candidate_id], [revision_number], [npr_revision_number], [mapping_version],
                [stale], [design_json], [saved_by_user_id], [created_at])
             VALUES (:candidateId, :number, :nprRevision, :mappingVersion, :stale, :design, :savedBy, :createdAt)',
            [
                'candidateId' => $candidateId,
                'number' => $revision->revisionNumber,
                'nprRevision' => $revision->sourceNprRevision,
                'mappingVersion' => $revision->mappingVersion,
                'stale' => $revision->stale ? 1 : 0,
                'design' => json_encode($revision->design, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'savedBy' => $revision->createdByUserId,
                'createdAt' => AzureSqlValueMapper::formatDateTime($revision->createdAt),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function candidate(array $row): CableFitCandidate
    {
        return new CableFitCandidate(
            strtolower(AzureSqlValueMapper::stringValue($row['public_id'] ?? null)),
            AzureSqlValueMapper::nullableString($row['npr_request_public_id'] ?? null),
            AzureSqlValueMapper::nullableString($row['npr_variant_id'] ?? null),
            AzureSqlValueMapper::stringValue($row['candidate_name'] ?? null),
            AzureSqlValueMapper::intValue($row['current_revision_number'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['created_at'] ?? null, 'created_at'),
            AzureSqlValueMapper::requireDateTime($row['updated_at'] ?? null, 'updated_at'),
            AzureSqlValueMapper::intValue($row['created_by_user_id'] ?? null),
            AzureSqlValueMapper::intValue($row['updated_by_user_id'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function design(mixed $decoded): array
    {
        /** @var array<string, mixed> $design */
        $design = is_array($decoded) ? $decoded : [];

        return $design;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function revision(array $row): CandidateRevision
    {
        $design = json_decode(AzureSqlValueMapper::stringValue($row['design_json'] ?? null), true);

        return new CandidateRevision(
            strtolower(AzureSqlValueMapper::stringValue($row['public_id'] ?? null)),
            AzureSqlValueMapper::intValue($row['revision_number'] ?? null),
            $this->design($design),
            AzureSqlValueMapper::stringValue($row['mapping_version'] ?? null),
            AzureSqlValueMapper::boolValue($row['stale'] ?? null),
            AzureSqlValueMapper::nullableInt($row['npr_revision_number'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['created_at'] ?? null, 'created_at'),
            AzureSqlValueMapper::intValue($row['saved_by_user_id'] ?? null),
        );
    }
}
