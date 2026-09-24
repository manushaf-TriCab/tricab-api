<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * `[onboarding].[FormContributions]` (5.4). The table has an INSTEAD OF
 * trigger, so the insert does not use `OUTPUT` (refused without `INTO`); the
 * row and its audit row are written in one transaction.
 *
 * Reads only `[onboarding]` and `[identity]` — never `[onboarding_hr]`:
 * contributed parts exist only on `hr` forms, so they are ordinary data.
 *
 * ## Before the table exists
 *
 * `create-onboarding-form-contributions.sql` is applied only with the owner's
 * approval. Until then every form still has to open and be reviewed, so reads
 * answer "nothing contributed" and only a write fails, saying why — the
 * pattern `AzureSqlOnboardingAccountDirectory` uses for its optional table.
 */
final class AzureSqlOnboardingFormContributionRepository implements OnboardingFormContributionRepository
{
    use AzureSqlTransaction;

    private const CONTEXT = <<<'SQL'
        SELECT
            a.[AssignmentId],
            CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
            a.[UserId],
            t.[ManagerUserId],
            fd.[Title],
            fd.[AccessGroup],
            a.[Status],
            fdv.[SchemaJson]
        FROM [onboarding].[Assignments] a
        JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
        JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
        LEFT JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
        WHERE a.[ItemType] = N'form'
          AND a.[WithdrawnAtUtc] IS NULL
        SQL;

    private ?bool $tableExists = null;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    private function tableExists(): bool
    {
        if ($this->tableExists === null) {
            $rows = $this->connection->fetchAll("SELECT OBJECT_ID(N'[onboarding].[FormContributions]', N'U') AS [ObjectId]");
            $this->tableExists = $rows !== [] && ($rows[0]['ObjectId'] ?? null) !== null;
        }

        return $this->tableExists;
    }

    public function findContext(string $assignmentPublicId): ?FormContributionContext
    {
        $rows = $this->connection->fetchAll(
            self::CONTEXT . ' AND a.[PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :assignment)',
            [':assignment' => $assignmentPublicId],
        );

        return $rows === [] ? null : self::context($rows[0]);
    }

    public function latest(int $assignmentId): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT c.[Role], c.[AnswersJson]
            FROM [onboarding].[FormContributions] c
            WHERE c.[AssignmentId] = :assignmentId
              AND c.[FormContributionId] = (
                  SELECT MAX(l.[FormContributionId]) FROM [onboarding].[FormContributions] l
                  WHERE l.[AssignmentId] = c.[AssignmentId] AND l.[Role] = c.[Role]
              )
            SQL,
            [':assignmentId' => $assignmentId],
        );

        $latest = [];
        foreach ($rows as $row) {
            $latest[AzureSqlValueMapper::stringValue($row['Role'] ?? '')] = self::object($row['AnswersJson'] ?? null);
        }

        return $latest;
    }

    public function append(FormContributionContext $form, string $role, int $contributorUserId, array $flatAnswers, OnboardingAdminEvent $event, ActorContext $actor): void
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('onboarding.FormContributions does not exist yet: apply create-onboarding-form-contributions.sql.');
        }
        $this->runInTransaction($this->connection, function () use ($form, $role, $contributorUserId, $flatAnswers, $event, $actor): void {
            $this->connection->execute(
                'INSERT INTO [onboarding].[FormContributions] ([AssignmentId], [Role], [ContributorUserId], [AnswersJson]) VALUES (:assignmentId, :role, :contributor, :answers)',
                [
                    ':assignmentId' => $form->assignmentId,
                    ':role' => $role,
                    ':contributor' => $contributorUserId,
                    ':answers' => json_encode((object) $flatAnswers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            );
            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        });
    }

    public function formsForManager(int $managerUserId): array
    {
        $rows = $this->connection->fetchAll(
            self::CONTEXT . " AND t.[ManagerUserId] = :manager AND a.[Status] <> N'completed' AND fdv.[SchemaJson] LIKE N'%\"filledBy\":\"manager\"%'",
            [':manager' => $managerUserId],
        );

        // The LIKE only narrows the read; the schema itself decides.
        return array_values(array_filter(array_map(self::context(...), $rows), static function (FormContributionContext $form): bool {
            foreach (is_array($form->schema['fields'] ?? null) ? $form->schema['fields'] : [] as $field) {
                if (FormSchemaRules::filledBy($field) === FormSchemaRules::FILLED_BY_MANAGER) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @param array<string, mixed> $row */
    private static function context(array $row): FormContributionContext
    {
        return new FormContributionContext(
            AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? '')),
            AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            AzureSqlValueMapper::nullableInt($row['ManagerUserId'] ?? null),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::stringValue($row['AccessGroup'] ?? ''),
            AssignmentStatus::fromStorage($row['Status'] ?? null),
            self::object($row['SchemaJson'] ?? null),
        );
    }

    /**
     * A JSON object column as an array with string keys; anything else is empty.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $json): array
    {
        $decoded = json_decode(AzureSqlValueMapper::stringValue($json ?? '{}'), true);
        $object = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }
}
