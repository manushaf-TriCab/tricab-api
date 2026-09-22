<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;

/**
 * Turns "this person, this assignment, this version" into the internal keys a
 * write needs — or into nothing at all.
 *
 * ## Every condition is in the one statement
 *
 * Ownership, item type, withdrawal and content version are all predicates in a
 * single `WHERE`, and the method returns null when any of them fails. There is
 * deliberately no version that resolves an assignment and lets the caller check
 * the rest: a caller that received the row and then compared the user id would
 * be one early return away from handing somebody another person's induction,
 * and a caller that compared the version would be one away from writing an
 * acknowledgement against wording nobody read.
 *
 * The cost is that the caller cannot tell *which* condition failed. That is the
 * point — work order invariant 4 wants not-found and not-yours indistinguishable
 * — with one exception: a superseded version has to be distinguishable, because
 * the SPA reloads rather than showing an error. So {@see locate()} is asked
 * twice on that path: once with the version and once without, and a row that
 * appears only the second time is a supersession rather than a stranger's item.
 * The second query runs only on the failure path.
 *
 * ## `CONVERT(NVARCHAR(36), …)` on both sides
 *
 * A `UNIQUEIDENTIFIER` compared against a bound string is a conversion SQL
 * Server will do implicitly and inconsistently — casing and braces both bite.
 * Converting the column is explicit, matches how every read in this module
 * projects a public id, and the tables are small enough that losing the index
 * seek on `UQ_…_PublicId` costs nothing measurable.
 */
final class AzureSqlOnboardingAssignmentLocator
{
    /** The three shapes of the same lookup, differing only in which version table they join. */
    private const ITEM_SQL = [
        'training' => [
            'column' => 'TrainingModuleVersionId',
            'table' => '[onboarding].[TrainingModuleVersions]',
        ],
        'policy' => [
            'column' => 'PolicyVersionId',
            'table' => '[onboarding].[PolicyVersions]',
        ],
        'form' => [
            'column' => 'FormDefinitionVersionId',
            'table' => '[onboarding].[FormDefinitionVersions]',
        ],
    ];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    /**
     * @param 'training'|'policy'|'form' $itemType
     *
     * @return array{assignmentId: int, versionId: int, status: string, versionPublicId: string}|null
     */
    public function locate(
        int $userId,
        string $assignmentPublicId,
        string $itemType,
        ?string $expectedVersionPublicId = null,
    ): ?array {
        $shape = self::ITEM_SQL[$itemType];
        $params = [
            ':userId' => $userId,
            ':assignmentPublicId' => $assignmentPublicId,
            ':itemType' => $itemType,
        ];

        $versionPredicate = '';
        if ($expectedVersionPublicId !== null) {
            $versionPredicate = ' AND CONVERT(NVARCHAR(36), v.[PublicId]) = :versionPublicId';
            $params[':versionPublicId'] = $expectedVersionPublicId;
        }

        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
                SELECT TOP (1)
                    a.[AssignmentId],
                    a.[%1$s] AS [VersionId],
                    a.[Status],
                    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VersionPublicId]
                FROM [onboarding].[Assignments] a
                JOIN %2$s v ON v.[%1$s] = a.[%1$s]
                WHERE a.[UserId] = :userId
                  AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
                  AND a.[ItemType] = :itemType
                  AND a.[WithdrawnAtUtc] IS NULL%3$s
                SQL,
                $shape['column'],
                $shape['table'],
                $versionPredicate,
            ),
            $params,
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'assignmentId' => AzureSqlValueMapper::intValue($row['AssignmentId'] ?? null),
            'versionId' => AzureSqlValueMapper::intValue($row['VersionId'] ?? null),
            'status' => AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
            'versionPublicId' => AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
        ];
    }

    /**
     * Whether an assignment that failed a versioned lookup exists at all for
     * this person.
     *
     * The one thing a caller is allowed to learn from a failure, and only
     * because the alternative is worse: telling somebody whose tab was open
     * across a publish that their induction does not exist would have them
     * ringing HR about a missing record that is sitting in front of them.
     */
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function existsForPerson(int $userId, string $assignmentPublicId, string $itemType): bool
    {
        return $this->locate($userId, $assignmentPublicId, $itemType) !== null;
    }
}
