<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprJson;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprTransition;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Shared\Time\Clock;
use RuntimeException;

/**
 * NPR requests on Azure SQL: `[npr].[Requests]` (the header) and
 * `[npr].[RequestRevisions]` (the answers), with the history in `[npr].[Activity]`.
 * Schema: `create-npr-schema.sql`.
 *
 * ## Concurrency
 *
 * A save reads the request `WITH (UPDLOCK, HOLDLOCK, ROWLOCK)` inside its
 * transaction and compares the `ROWVERSION` token the client last read
 * (`CONVERT(VARCHAR(18), CAST([RowVersion] AS BINARY(8)), 1)`, e.g. `0x00000000000007D1`). A second
 * save of the same request waits on the lock, reads the new version and gets
 * {@see NprRequestConflict}; nothing is overwritten. The token moves on every
 * write to the header row, including a draft's in-place save, which leaves
 * the revision number alone.
 *
 * ## Request numbers
 *
 * `NPR-YYYY-NNNN`, as the source. The source counted rows; this takes the highest
 * suffix of the year plus one under a range lock, so a gap cannot reissue a
 * number. The unique index is the backstop.
 */
final class AzureSqlNprRequestRepository implements NprRequestRepository
{
    use AzureSqlTransaction;

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const SELECT = '
        SELECT r.[PublicId], r.[RequestNumber], r.[CurrentRevision], r.[Status], r.[ScreeningOutcome], r.[Title],
               r.[CustomerName], r.[UrgencyLevel], r.[RequesterUserId], r.[RequesterEmail], r.[RequesterName],
               r.[RequesterBranch], r.[CreatedAt], r.[UpdatedAt], r.[SubmittedAt], r.[TsdReceivedAt],
               r.[AssignedManagerUserId], r.[AssignedManagerEmail], r.[AssignedManagerName],
               r.[TsdOwnerUserId], r.[TsdOwnerEmail], r.[TsdOwnerName],
               r.[ScreeningSignatoryUserId], r.[ScreeningSignatoryEmail], r.[ScreeningSignatoryName],
               r.[EndorsedAt], r.[TsdAcknowledgedAt], r.[TsdAcknowledgedByName],
               r.[ScreeningDecidedAt], r.[ScreeningRationale], r.[ScreeningConditions],
               CONVERT(VARCHAR(18), CAST(r.[RowVersion] AS BINARY(8)), 1) AS [Version],
               v.[AnswersJson], v.[VariantsJson]
        FROM [npr].[Requests] r
        INNER JOIN [npr].[RequestRevisions] v
            ON v.[RequestId] = r.[RequestId] AND v.[RevisionNumber] = r.[CurrentRevision]';

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function create(NewNprRequest $draft): NprRequest
    {
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());
        $year = $this->clock->now()->format('Y');

        $publicId = $this->runInTransaction($this->connection, function () use ($draft, $now, $year): string {
            $next = $this->connection->fetchAll(
                'SELECT ISNULL(MAX(CAST(SUBSTRING([RequestNumber], 10, 10) AS INT)), 0) + 1 AS [Next]
                 FROM [npr].[Requests] WITH (UPDLOCK, HOLDLOCK)
                 WHERE [RequestNumber] LIKE :prefix',
                ['prefix' => 'NPR-' . $year . '-%'],
            )[0]['Next'] ?? 1;
            $number = sprintf('NPR-%s-%04d', $year, AzureSqlValueMapper::intValue($next));

            $inserted = $this->connection->fetchAll(
                'INSERT INTO [npr].[Requests]
                    ([RequestNumber], [CurrentRevision], [Status], [Title], [CustomerName], [UrgencyLevel],
                     [RequesterUserId], [RequesterEmail], [RequesterName], [RequesterBranch], [CreatedAt], [UpdatedAt])
                 OUTPUT INSERTED.[RequestId], INSERTED.[PublicId]
                 VALUES (:number, 1, N\'draft\', :title, :customer, :urgency,
                     :userId, :email, :name, :branch, :createdAt, :updatedAt)',
                [
                    'number' => $number,
                    'title' => $draft->title,
                    'customer' => $draft->customerName,
                    'urgency' => $draft->urgencyLevel,
                    'userId' => $draft->requesterUserId,
                    'email' => $draft->requesterEmail,
                    'name' => $draft->requesterName,
                    'branch' => $draft->requesterBranch,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ],
            )[0] ?? throw new RuntimeException('NPR request insert returned no row.');
            $requestId = AzureSqlValueMapper::intValue($inserted['RequestId'] ?? null);

            $this->connection->execute(
                'INSERT INTO [npr].[RequestRevisions]
                    ([RequestId], [RevisionNumber], [AnswersJson], [VariantsJson], [CreatedByUserId],
                     [CreatedByEmail], [CreatedByName], [CreatedAt], [ChangeSummary])
                 VALUES (:requestId, 1, :answers, :variants, :userId, :email, :name, :createdAt, N\'Initial draft created\')',
                [
                    'requestId' => $requestId,
                    'answers' => self::encode(NprJson::answers($draft->answers)),
                    'variants' => self::encode(NprJson::variants($draft->variants)),
                    'userId' => $draft->requesterUserId,
                    'email' => $draft->requesterEmail,
                    'name' => $draft->requesterName,
                    'createdAt' => $now,
                ],
            );
            $this->activity(
                $requestId,
                1,
                $draft->requesterUserId,
                $draft->requesterEmail,
                $draft->requesterName,
                'created_draft',
                null,
                RequestStatus::Draft->value,
                'Initial draft created',
                $now,
            );

            return strtolower(AzureSqlValueMapper::stringValue($inserted['PublicId'] ?? null));
        });

        return $this->find($publicId) ?? throw new RuntimeException('NPR request vanished after insert.');
    }

    public function find(string $publicId): ?NprRequest
    {
        $publicId = strtolower(trim($publicId));
        if (preg_match(self::GUID, $publicId) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAll(self::SELECT . ' WHERE r.[PublicId] = :id', ['id' => $publicId])[0] ?? null;

        return $row === null ? null : self::request($row);
    }

    public function listForRequester(int $userId): array
    {
        return $this->many(' WHERE r.[RequesterUserId] = :userId', ['userId' => $userId]);
    }

    public function listForTsd(): array
    {
        // Constants from the enum, not user input. A request returned to Sales after TSD
        // received it stays visible; a manager-stage return (TsdReceivedAt null) does not.
        $statuses = implode(', ', array_map(
            static fn (RequestStatus $status): string => "N'" . $status->value . "'",
            RequestStatus::tsdVisible(),
        ));

        return $this->many(
            ' WHERE (r.[Status] IN (' . $statuses . ")\n               OR (r.[Status] = N'returned_to_sales' AND r.[TsdReceivedAt] IS NOT NULL))",
            [],
        );
    }

    public function saveDraft(string $publicId, string $expectedVersion, NprDraftChange $change): ?NprRequest
    {
        $publicId = strtolower(trim($publicId));
        if (preg_match(self::GUID, $publicId) !== 1) {
            return null;
        }
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());

        $found = $this->runInTransaction($this->connection, function () use ($publicId, $expectedVersion, $change, $now): bool {
            $locked = $this->connection->fetchAll(
                'SELECT [RequestId], [Status], [CurrentRevision], CONVERT(VARCHAR(18), CAST([RowVersion] AS BINARY(8)), 1) AS [Version]
                 FROM [npr].[Requests] WITH (UPDLOCK, HOLDLOCK, ROWLOCK)
                 WHERE [PublicId] = :id',
                ['id' => $publicId],
            )[0] ?? null;
            if ($locked === null) {
                return false;
            }

            $currentVersion = AzureSqlValueMapper::stringValue($locked['Version'] ?? null);
            if (strcasecmp($currentVersion, trim($expectedVersion)) !== 0) {
                throw new NprRequestConflict($currentVersion);
            }
            $status = RequestStatus::tryFrom(AzureSqlValueMapper::stringValue($locked['Status'] ?? null));
            if ($status === null) {
                throw new RuntimeException('NPR request has an unknown status.');
            }
            if (!$status->isEditableBySales()) {
                throw new NprRequestNotEditable($status->value);
            }

            $requestId = AzureSqlValueMapper::intValue($locked['RequestId'] ?? null);
            $current = AzureSqlValueMapper::intValue($locked['CurrentRevision'] ?? null);
            $summary = $change->changeSummary;
            $answers = self::encode(NprJson::answers($change->answers));
            $variants = self::encode(NprJson::variants($change->variants));

            if ($status === RequestStatus::ReturnedToSales) {
                // An amended returned request is a new immutable revision: the earlier, signed one stays.
                $revision = $current + 1;
                $this->connection->execute(
                    'INSERT INTO [npr].[RequestRevisions]
                        ([RequestId], [RevisionNumber], [AnswersJson], [VariantsJson], [CreatedByUserId],
                         [CreatedByEmail], [CreatedByName], [CreatedAt], [ChangeSummary])
                     VALUES (:requestId, :revision, :answers, :variants, :userId, :email, :name, :createdAt, :summary)',
                    [
                        'requestId' => $requestId,
                        'revision' => $revision,
                        'answers' => $answers,
                        'variants' => $variants,
                        'userId' => $change->actorUserId,
                        'email' => $change->actorEmail,
                        'name' => $change->actorName,
                        'createdAt' => $now,
                        'summary' => $summary ?? 'Amended returned request',
                    ],
                );
            } else {
                $revision = $current;
                $this->connection->execute(
                    'UPDATE [npr].[RequestRevisions]
                     SET [AnswersJson] = :answers, [VariantsJson] = :variants, [ChangeSummary] = :summary
                     WHERE [RequestId] = :requestId AND [RevisionNumber] = :revision',
                    [
                        'answers' => $answers,
                        'variants' => $variants,
                        'summary' => $summary ?? 'Saved draft changes',
                        'requestId' => $requestId,
                        'revision' => $revision,
                    ],
                );
            }

            $this->connection->execute(
                'UPDATE [npr].[Requests]
                 SET [CurrentRevision] = :revision, [Title] = :title, [CustomerName] = :customer,
                     [UrgencyLevel] = :urgency, [UpdatedAt] = :updatedAt
                 WHERE [RequestId] = :requestId',
                [
                    'revision' => $revision,
                    'title' => $change->title,
                    'customer' => $change->customerName,
                    'urgency' => $change->urgencyLevel,
                    'updatedAt' => $now,
                    'requestId' => $requestId,
                ],
            );
            $this->activity(
                $requestId,
                $revision,
                $change->actorUserId,
                $change->actorEmail,
                $change->actorName,
                'saved_draft',
                $status->value,
                $status->value,
                $summary ?? 'Saved draft changes',
                $now,
            );

            return true;
        });

        return $found ? $this->find($publicId) : null;
    }

    public function listForManager(int $userId): array
    {
        return $this->many(
            " WHERE r.[AssignedManagerUserId] = :userId AND r.[Status] <> N'draft'",
            ['userId' => $userId],
        );
    }

    public function transition(string $publicId, string $expectedVersion, NprTransition $transition): ?NprRequest
    {
        $publicId = strtolower(trim($publicId));
        if (preg_match(self::GUID, $publicId) !== 1) {
            return null;
        }
        $now = AzureSqlValueMapper::formatDateTime($this->clock->now());

        $found = $this->runInTransaction($this->connection, function () use ($publicId, $expectedVersion, $transition, $now): bool {
            $locked = $this->connection->fetchAll(
                'SELECT [RequestId], [Status], [CurrentRevision], CONVERT(VARCHAR(18), CAST([RowVersion] AS BINARY(8)), 1) AS [Version]
                 FROM [npr].[Requests] WITH (UPDLOCK, HOLDLOCK, ROWLOCK)
                 WHERE [PublicId] = :id',
                ['id' => $publicId],
            )[0] ?? null;
            if ($locked === null) {
                return false;
            }

            $currentVersion = AzureSqlValueMapper::stringValue($locked['Version'] ?? null);
            if (strcasecmp($currentVersion, trim($expectedVersion)) !== 0) {
                throw new NprRequestConflict($currentVersion);
            }
            $status = RequestStatus::tryFrom(AzureSqlValueMapper::stringValue($locked['Status'] ?? null));
            if ($status === null) {
                throw new RuntimeException('NPR request has an unknown status.');
            }
            if (!in_array($status, $transition->from, true)) {
                throw NprWorkflowRefused::notAllowedIn($status->value);
            }

            $requestId = AzureSqlValueMapper::intValue($locked['RequestId'] ?? null);
            $revision = AzureSqlValueMapper::intValue($locked['CurrentRevision'] ?? null);
            $actor = $transition->actor;

            $sets = ['[UpdatedAt] = :updatedAt'];
            $params = ['updatedAt' => $now, 'requestId' => $requestId];
            if ($transition->to !== null) {
                $sets[] = '[Status] = :status';
                $params['status'] = $transition->to->value;
            }
            foreach ([
                ['AssignedManager', $transition->assignManager, 'manager'],
                ['TsdOwner', $transition->assignTsdOwner, 'owner'],
                ['ScreeningSignatory', $transition->assignSignatory, 'signatory'],
            ] as [$column, $person, $key]) {
                if ($person instanceof NprPerson) {
                    $sets[] = "[{$column}UserId] = :{$key}Id, [{$column}Email] = :{$key}Email, [{$column}Name] = :{$key}Name";
                    $params[$key . 'Id'] = $person->userId;
                    $params[$key . 'Email'] = $person->email;
                    $params[$key . 'Name'] = $person->name;
                }
            }
            if ($transition->markSubmitted) {
                $sets[] = '[SubmittedAt] = :submittedAt';
                $params['submittedAt'] = $now;
            }
            if ($transition->markEndorsed) {
                // Receipt is the first endorsement only; a repeat keeps the original (the TSD clocks run from it).
                $sets[] = '[EndorsedAt] = :endorsedAt, [TsdReceivedAt] = COALESCE([TsdReceivedAt], :receivedAt)';
                $params['endorsedAt'] = $now;
                $params['receivedAt'] = $now;
            }
            if ($transition->markAcknowledged) {
                $sets[] = '[TsdAcknowledgedAt] = :ackAt, [TsdAcknowledgedByName] = :ackName, [TsdAcknowledgedByUserId] = :ackId';
                $params['ackAt'] = $now;
                $params['ackName'] = $actor->name;
                $params['ackId'] = $actor->userId;
            }
            if ($transition->decision !== null) {
                $sets[] = '[ScreeningOutcome] = :outcome, [ScreeningDecidedAt] = :decidedAt, [ScreeningRationale] = :rationale, [ScreeningConditions] = :conditions';
                $params['outcome'] = $transition->decision->outcome;
                $params['decidedAt'] = $now;
                $params['rationale'] = $transition->decision->rationale;
                $params['conditions'] = $transition->decision->conditions;
            }
            $this->connection->execute('UPDATE [npr].[Requests] SET ' . implode(', ', $sets) . ' WHERE [RequestId] = :requestId', $params);

            if ($transition->answerPatch !== [] || $transition->signRevision || $transition->decision !== null) {
                $row = $this->connection->fetchAll(
                    'SELECT [AnswersJson] FROM [npr].[RequestRevisions] WITH (UPDLOCK, ROWLOCK)
                     WHERE [RequestId] = :requestId AND [RevisionNumber] = :revision',
                    ['requestId' => $requestId, 'revision' => $revision],
                )[0] ?? throw new RuntimeException('NPR request has no current revision row.');
                $answers = array_merge(
                    NprJson::decodeObject(AzureSqlValueMapper::stringValue($row['AnswersJson'] ?? null)),
                    $transition->answerPatch,
                );
                $flags = ($transition->signRevision ? ', [IsSigned] = 1' : '')
                    . ($transition->decision !== null ? ', [IsScreened] = 1' : '');
                $this->connection->execute(
                    'UPDATE [npr].[RequestRevisions] SET [AnswersJson] = :answers' . $flags . '
                     WHERE [RequestId] = :requestId AND [RevisionNumber] = :revision',
                    [
                        'answers' => self::encode(NprJson::answers($answers)),
                        'requestId' => $requestId,
                        'revision' => $revision,
                    ],
                );
            }

            if ($transition->endorsement !== null) {
                $this->connection->execute(
                    'INSERT INTO [npr].[Endorsements]
                        ([RequestId], [RevisionNumber], [Action], [ManagerUserId], [ManagerEmail], [ManagerName],
                         [DeclarationAgreed], [Comment], [CreatedAt])
                     VALUES (:requestId, :revision, :action, :userId, :email, :name, :declaration, :comment, :createdAt)',
                    [
                        'requestId' => $requestId,
                        'revision' => $revision,
                        'action' => $transition->endorsement['action'],
                        'userId' => $actor->userId,
                        'email' => $actor->email,
                        'name' => $actor->name,
                        'declaration' => $transition->endorsement['declaration'] ? 1 : 0,
                        'comment' => $transition->endorsement['comment'],
                        'createdAt' => $now,
                    ],
                );
            }

            $this->activity(
                $requestId,
                $revision,
                $actor->userId,
                $actor->email,
                $actor->name,
                $transition->action,
                $status->value,
                ($transition->to ?? $status)->value,
                $transition->summary,
                $now,
                $transition->actorRole,
                $transition->payload === [] ? null : self::encode($transition->payload),
            );

            return true;
        });

        return $found ? $this->find($publicId) : null;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<NprRequest>
     */
    private function many(string $where, array $params): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . $where . ' ORDER BY r.[UpdatedAt] DESC, r.[RequestId] DESC',
            $params,
        );

        return array_map(self::request(...), $rows);
    }

    private function activity(
        int $requestId,
        int $revision,
        int $userId,
        string $email,
        string $name,
        string $action,
        ?string $before,
        ?string $after,
        string $summary,
        string $now,
        string $role = 'requester',
        ?string $payloadJson = null,
    ): void {
        $this->connection->execute(
            'INSERT INTO [npr].[Activity]
                ([RequestId], [RevisionNumber], [ActorUserId], [ActorEmail], [ActorName], [ActorRole], [Action],
                 [BeforeStatus], [AfterStatus], [ChangeSummary], [PayloadJson], [CreatedAt])
             VALUES (:requestId, :revision, :userId, :email, :name, :role, :action,
                 :before, :after, :summary, :payload, :createdAt)',
            [
                'role' => $role,
                'payload' => $payloadJson,
                'requestId' => $requestId,
                'revision' => $revision,
                'userId' => $userId,
                'email' => $email,
                'name' => $name,
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'summary' => $summary,
                'createdAt' => $now,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private static function request(array $row): NprRequest
    {
        return new NprRequest(
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? null)),
            AzureSqlValueMapper::stringValue($row['RequestNumber'] ?? null),
            AzureSqlValueMapper::intValue($row['CurrentRevision'] ?? null),
            RequestStatus::from(AzureSqlValueMapper::stringValue($row['Status'] ?? null)),
            AzureSqlValueMapper::stringValue($row['Title'] ?? null),
            AzureSqlValueMapper::stringValue($row['CustomerName'] ?? null),
            AzureSqlValueMapper::stringValue($row['UrgencyLevel'] ?? null),
            AzureSqlValueMapper::intValue($row['RequesterUserId'] ?? null),
            AzureSqlValueMapper::stringValue($row['RequesterEmail'] ?? null),
            AzureSqlValueMapper::stringValue($row['RequesterName'] ?? null),
            AzureSqlValueMapper::stringValue($row['RequesterBranch'] ?? null),
            NprJson::decodeObject(AzureSqlValueMapper::stringValue($row['AnswersJson'] ?? null)),
            NprJson::decodeVariants(AzureSqlValueMapper::stringValue($row['VariantsJson'] ?? null)),
            AzureSqlValueMapper::stringValue($row['Version'] ?? null),
            AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt'),
            AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt'),
            AzureSqlValueMapper::nullableDateTime($row['SubmittedAt'] ?? null),
            AzureSqlValueMapper::nullableString($row['ScreeningOutcome'] ?? null),
            AzureSqlValueMapper::nullableDateTime($row['TsdReceivedAt'] ?? null),
            self::person($row, 'AssignedManager'),
            self::person($row, 'TsdOwner'),
            self::person($row, 'ScreeningSignatory'),
            AzureSqlValueMapper::nullableDateTime($row['EndorsedAt'] ?? null),
            AzureSqlValueMapper::nullableDateTime($row['TsdAcknowledgedAt'] ?? null),
            AzureSqlValueMapper::nullableString($row['TsdAcknowledgedByName'] ?? null),
            AzureSqlValueMapper::nullableDateTime($row['ScreeningDecidedAt'] ?? null),
            AzureSqlValueMapper::nullableString($row['ScreeningRationale'] ?? null),
            AzureSqlValueMapper::nullableString($row['ScreeningConditions'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private static function person(array $row, string $prefix): ?NprPerson
    {
        $userId = $row[$prefix . 'UserId'] ?? null;
        if ($userId === null) {
            return null;
        }

        return new NprPerson(
            AzureSqlValueMapper::intValue($userId),
            AzureSqlValueMapper::stringValue($row[$prefix . 'Email'] ?? null),
            AzureSqlValueMapper::stringValue($row[$prefix . 'Name'] ?? null),
        );
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
