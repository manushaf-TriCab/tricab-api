<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyContent;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Policies and acknowledgements, against `[onboarding]`.
 *
 * ## The acknowledgement is keyed on the version, and joined on it too
 *
 * `LEFT JOIN … ON ack.[UserId] = a.[UserId] AND ack.[PolicyVersionId] = pv.[PolicyVersionId]`.
 * Joining on the policy instead would report somebody as having acknowledged
 * wording they have never seen, the moment HR published a new version — which is
 * precisely the confusion A6 exists to prevent, arriving through a query rather
 * than through a data change.
 *
 * ## The declaration text is written from the version, not from the request
 *
 * `acknowledge()` takes it as a parameter and the application service fills it
 * from {@see PolicyContent}. The row is the evidence, and evidence whose wording
 * the signatory supplied is not evidence.
 *
 * ## The signed declaration, and a database without its column
 *
 * `[DeclarationJson]` arrives with `alter-onboarding-policy-declarations.sql`.
 * Until that is applied, reads select NULL in its place and behave as before,
 * and only writing a declaration fails, naming the script.
 */
final class AzureSqlOnboardingPolicyRepository implements OnboardingPolicyRepository
{
    use AzureSqlTransaction;
    use AzureSqlOnboardingStatusWriter;
    use UniqueConstraintTranslator;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly AzureSqlOnboardingAssignmentLocator $locator,
    ) {
    }

    private ?bool $declarationColumnExists = null;

    private function declarationColumnExists(): bool
    {
        if ($this->declarationColumnExists === null) {
            $rows = $this->connection->fetchAll(
                "SELECT COL_LENGTH(N'[onboarding].[PolicyAcknowledgements]', N'DeclarationJson') AS [Length]",
            );
            $this->declarationColumnExists = $rows !== [] && ($rows[0]['Length'] ?? null) !== null;
        }

        return $this->declarationColumnExists;
    }

    public function findPolicy(int $userId, string $assignmentPublicId): ?OnboardingPolicyDetail
    {
        $declarationColumn = $this->declarationColumnExists() ? 'ack.[DeclarationJson]' : 'CAST(NULL AS NVARCHAR(MAX))';
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
                p.[Title],
                CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VersionPublicId],
                pv.[VersionNumber],
                pv.[ContentJson],
                CONVERT(VARCHAR(10), pv.[EffectiveFrom], 23) AS [EffectiveFrom],
                a.[Status],
                CONVERT(VARCHAR(33), ack.[AcknowledgedAtUtc], 127) AS [AcknowledgedAtUtc],
                pv.[RequiresAcknowledgement],
                pv.[DocumentReference],
                pv.[IssuedLabel],
                pv.[ReviewDueLabel],
                p.[Summary],
                p.[ChapterTitle],
                p.[Slug],
                {$declarationColumn} AS [DeclarationJson]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[PolicyAcknowledgements] ack
                ON ack.[UserId] = a.[UserId]
               AND ack.[PolicyVersionId] = pv.[PolicyVersionId]
            WHERE a.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
              AND a.[ItemType] = N'policy'
              AND a.[WithdrawnAtUtc] IS NULL
            SQL,
            [':userId' => $userId, ':assignmentPublicId' => $assignmentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $title = AzureSqlValueMapper::stringValue($row['Title'] ?? '');
        $content = PolicyContent::fromJson(
            AzureSqlValueMapper::stringValue($row['ContentJson'] ?? '[]'),
            $title,
        );

        return new OnboardingPolicyDetail(
            assignmentPublicId: AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? ''),
            title: $title,
            versionPublicId: AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
            versionNumber: AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            status: AssignmentStatus::fromStorage($row['Status'] ?? null),
            contentBlocks: $content->blocks(),
            declarationText: $content->declaration(),
            effectiveFrom: AzureSqlValueMapper::nullableString($row['EffectiveFrom'] ?? null),
            acknowledgedAtUtc: AzureSqlValueMapper::nullableString($row['AcknowledgedAtUtc'] ?? null),
            requiresAcknowledgement: AzureSqlValueMapper::boolValue($row['RequiresAcknowledgement'] ?? true),
            summary: AzureSqlValueMapper::nullableString($row['Summary'] ?? null),
            chapterTitle: AzureSqlValueMapper::nullableString($row['ChapterTitle'] ?? null),
            documentReference: AzureSqlValueMapper::nullableString($row['DocumentReference'] ?? null),
            issuedLabel: AzureSqlValueMapper::nullableString($row['IssuedLabel'] ?? null),
            reviewDueLabel: AzureSqlValueMapper::nullableString($row['ReviewDueLabel'] ?? null),
            declarationKind: PolicyDeclaration::kindFor(
                AzureSqlValueMapper::nullableString($row['DocumentReference'] ?? null),
                AzureSqlValueMapper::nullableString($row['Slug'] ?? null),
            ),
            declaration: PolicyDeclaration::fromStoredJson(AzureSqlValueMapper::nullableString($row['DeclarationJson'] ?? null)),
        );
    }

    public function acknowledge(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        string $declarationText,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
        ?string $declarationJson = null,
    ): OnboardingPolicyDetail {
        if ($declarationJson !== null && !$this->declarationColumnExists()) {
            throw new \RuntimeException('onboarding.PolicyAcknowledgements has no DeclarationJson column yet: apply alter-onboarding-policy-declarations.sql.');
        }

        $located = $this->locator->locate($userId, $assignmentPublicId, 'policy', $expectedVersionPublicId);

        if ($located === null) {
            if ($this->locator->existsForPerson($userId, $assignmentPublicId, 'policy')) {
                throw ContentVersionSuperseded::reload();
            }

            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        $this->runInTransaction($this->connection, function () use (
            $located,
            $userId,
            $assignmentPublicId,
            $declarationText,
            $idempotencyKey,
            $transition,
            $declarationJson,
        ): void {
            /* UQ_onboarding_PolicyAcknowledgements_UserVersion is the guarantee;
               this translates its violation into something a browser can read.
               There is no preceding SELECT to check — a check would leave a
               window, and the constraint would win the race anyway. */
            $this->translatingUniqueConstraintViolation(
                function () use ($located, $userId, $declarationText, $idempotencyKey, $declarationJson): void {
                    $parameters = [
                        ':assignmentId' => $located['assignmentId'],
                        ':userId' => $userId,
                        ':policyVersionId' => $located['versionId'],
                        ':declarationText' => $declarationText,
                        ':idempotencyKey' => $idempotencyKey,
                    ];
                    if ($declarationJson === null) {
                        $this->connection->execute(
                            <<<'SQL'
                            INSERT INTO [onboarding].[PolicyAcknowledgements]
                                ([AssignmentId], [UserId], [PolicyVersionId], [DeclarationText], [IdempotencyKey])
                            VALUES (:assignmentId, :userId, :policyVersionId, :declarationText, :idempotencyKey)
                            SQL,
                            $parameters,
                        );

                        return;
                    }
                    $this->connection->execute(
                        <<<'SQL'
                        INSERT INTO [onboarding].[PolicyAcknowledgements]
                            ([AssignmentId], [UserId], [PolicyVersionId], [DeclarationText], [IdempotencyKey], [DeclarationJson])
                        VALUES (:assignmentId, :userId, :policyVersionId, :declarationText, :idempotencyKey, :declarationJson)
                        SQL,
                        $parameters + [':declarationJson' => $declarationJson],
                    );
                },
                static fn (): Throwable => OnboardingWriteConflict::alreadyRecorded('acknowledgement'),
            );

            $this->applyTransition(
                $this->connection,
                $located['assignmentId'],
                $assignmentPublicId,
                $userId,
                $transition,
            );
        });

        $detail = $this->findPolicy($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $detail;
    }

    public function markRead(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        StatusTransition $transition,
    ): OnboardingPolicyDetail {
        $located = $this->locator->locate($userId, $assignmentPublicId, 'policy', $expectedVersionPublicId);

        if ($located === null) {
            if ($this->locator->existsForPerson($userId, $assignmentPublicId, 'policy')) {
                throw ContentVersionSuperseded::reload();
            }

            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        $this->runInTransaction($this->connection, function () use ($located, $userId, $assignmentPublicId, $transition): void {
            // No acknowledgement row, so no unique constraint to lean on: the
            // assignment itself is locked and its status checked, and a second
            // click waits here and then finds the item already finished.
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                SELECT a.[Status]
                FROM [onboarding].[Assignments] a WITH (UPDLOCK, HOLDLOCK)
                WHERE a.[AssignmentId] = :assignmentId
                SQL,
                [':assignmentId' => $located['assignmentId']],
            );
            if ($rows === [] || !AssignmentStatus::fromStorage($rows[0]['Status'] ?? null)->equals($transition->from)) {
                throw OnboardingWriteConflict::alreadyRecorded('reading of this policy');
            }

            $this->applyTransition($this->connection, $located['assignmentId'], $assignmentPublicId, $userId, $transition);
        });

        return $this->findPolicy($userId, $assignmentPublicId) ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }
}
