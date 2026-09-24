<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;

/**
 * Phase 2.5 — concurrency and idempotency, proved against the real database.
 *
 * Work order §6 asks for "optimistic concurrency where administrators can edit
 * the same record" and "idempotency keys for invitations, submissions, scheduled
 * work and future imports". Phase 2.2 put both in the schema: ROWVERSION on
 * thirteen tables, [onboarding].[OnboardingWriteIdempotency], and filtered
 * unique indexes on the natural keys. Until now neither had ever been executed.
 *
 * This is the companion to OnboardingSchemaInvariantsTest and follows its rules:
 * every refusal names the constraint, index or message it expects, and every
 * test runs inside a transaction that is always rolled back, because the target
 * is `tc` on tricab-mel and it is shared.
 *
 * What is deliberately NOT here: a repository. Phase 2.4 declined to write
 * repositories with no callers and that still holds. What Phase 3 needs from
 * 2.5 is the knowledge of how these mechanisms actually behave — the hex
 * transport form for a token, the fact that the ledger's unique index and not a
 * read-then-insert is what makes an acquisition safe — and that is what each
 * test records.
 *
 * Skips when Azure SQL is unreachable. Locally that means the VPN is down or the
 * serverless database is resuming, neither of which is a test failure.
 */
#[Group('live-azure-sql')]
final class OnboardingConcurrencyAndIdempotencyTest extends TestCase
{
    /**
     * Every table in the two onboarding schemas, and how it is protected from a
     * second writer. There are exactly three answers, and the coverage test
     * below fails if a table appears that has not been given one — which is the
     * point of listing them by hand rather than deriving the list from the
     * database.
     *
     * ROW_VERSIONED — two administrators can open it, so it carries a ROWVERSION
     * and a write must carry the token it read.
     */
    private const ROW_VERSIONED = [
        'onboarding.Assignments',
        'onboarding.FormDefinitions',
        'onboarding.FormDefinitionVersions',
        'onboarding.FormDrafts',
        'onboarding.Policies',
        'onboarding.PolicyVersions',
        'onboarding.PresentationProgress',
        'onboarding.Questions',
        'onboarding.TraineeProfiles',
        'onboarding.TrainingModules',
        'onboarding.TrainingModuleVersions',
        'onboarding_hr.EmergencyContacts',
        'onboarding_hr.PayrollDetails',
        'onboarding_hr.RestrictedPersonalDetails',
    ];

    /**
     * APPEND_ONLY — a row is written once and never edited, so there is no
     * second writer to lose to. Most are held to it by a trigger; the rest by
     * having no update path at all.
     */
    private const APPEND_ONLY = [
        'onboarding.AttemptAnswers',
        'onboarding.Attempts',
        'onboarding.DocumentReferences',
        'onboarding.FormContributions',
        'onboarding.FormSubmissions',
        'onboarding.NotificationLog',
        'onboarding.PolicyAcknowledgements',
        'onboarding_hr.FieldClassifications',
        'onboarding_hr.RestrictedSubmissionData',
    ];

    /**
     * SINGLE_WRITER — one writer by construction, so optimistic concurrency
     * would guard a race that cannot occur.
     *
     * [OnboardingWriteIdempotency] rows are owned by whoever won the acquisition
     * and updated by nobody else; [CompletionProjection] is written by the
     * projection and by nothing else; [RetentionClasses] is six rows of
     * reference data an owner edits deliberately and rarely.
     *
     * [RetentionClasses] is the one with a residual: two owners setting a
     * retention figure in the same minute would have the second silently win.
     * [DecidedByUserId] and [DecidedAtUtc] record who did, so the loss is
     * visible after the fact, which for a table with six rows and a decision
     * behind each of them is judged enough.
     */
    private const SINGLE_WRITER = [
        'onboarding.CompletionProjection',
        'onboarding.OnboardingWriteIdempotency',
        'onboarding.RetentionClasses',
    ];

    /**
     * Reading a token out and binding it back in — IN THIS TEST ONLY.
     *
     * A ROWVERSION is eight raw bytes, and PDO_SQLSRV binds a PHP string as
     * NVARCHAR, so handing the raw bytes back as a parameter never works. There
     * is a test for that below, because it is the kind of failure that costs an
     * afternoon.
     *
     * These tests speak bare PDO, so they convert through hex: `0x0000000000053FD1`
     * out, and back in with style 1, which is what makes CONVERT read `0x...` as
     * hex rather than as characters. Style 1 refuses NVARCHAR, hence the inner
     * VARCHAR cast that would otherwise look pointless.
     *
     * **Application code must NOT copy this.** The platform already solved it:
     * {@see \TriCab\Api\Infrastructure\Database\SqlBinary} binds a parameter as
     * PDO::PARAM_LOB with SQLSRV_ENCODING_BINARY, and
     * {@see \TriCab\Core\Product\Domain\ValueObject\RowVersionToken} carries
     * the token as Base64 for transport. A repository reads
     * `CONVERT(VARBINARY(8), [RowVersion])`, maps it with
     * `ProductRowVersionMapper::toToken()`, and compares with
     * `WHERE [RowVersion] = :expected` bound through `toSqlBinary()` —
     * see AzureSqlExpensePeriodRepository. Two encodings for one value in one
     * database is how one of them ends up wrong.
     */
    private const TOKEN_OUT = 'CONVERT(CHAR(18), CONVERT(VARBINARY(8), [RowVersion]), 1)';
    private const TOKEN_IN = 'CONVERT(BINARY(8), CONVERT(VARCHAR(18), :token), 1)';

    private ?PDO $pdo = null;

    private int $userId;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();

        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[onboarding].[OnboardingWriteIdempotency]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The onboarding schema is not applied to this database.');
            }

            $users = $pdo->query('SELECT TOP 1 UserId FROM [identity].[Users] ORDER BY UserId');
            $userId = $users === false ? null : $users->fetchColumn();
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (!is_numeric($userId)) {
            self::markTestSkipped('No users in identity.Users to attach synthetic onboarding rows to.');
        }

        $this->pdo = $pdo;
        $this->userId = (int) $userId;
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo = null;
    }

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params = []): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    /** @param array<string, mixed> $params */
    private function affected(string $sql, array $params = []): int
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params = []): mixed
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * A single value, as an int. The assertion is the point: a query returning
     * no row gives `false`, and `(int) false` is `0`, which for a COUNT test is
     * indistinguishable from a real answer of zero.
     *
     * @param array<string, mixed> $params
     */
    private function oneInt(string $sql, array $params = []): int
    {
        $value = $this->one($sql, $params);
        self::assertIsNumeric($value, 'Expected a numeric single value from: ' . $sql);

        return (int) $value;
    }

    /** @param array<string, mixed> $params */
    private function oneString(string $sql, array $params = []): string
    {
        $value = $this->one($sql, $params);
        self::assertTrue(
            is_string($value) || is_numeric($value),
            'Expected a string single value from: ' . $sql,
        );

        return (string) $value;
    }

    /**
     * Assert the database refused a write, AND that it refused it for the reason
     * the test is about. `expectException(PDOException::class)` alone would pass
     * just as happily against a mistyped column name.
     */
    private function assertRefusedBy(string $expectedInMessage, callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            self::assertStringContainsString(
                $expectedInMessage,
                $e->getMessage(),
                sprintf('The write was refused, but not by %s.', $expectedInMessage),
            );

            return;
        }

        self::fail(sprintf('The database accepted a write that %s should have refused.', $expectedInMessage));
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    private function aKey(string $prefix = 'idem'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    private function aFormVersion(string $slug): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup])
             VALUES (:slug, N'Synthetic', N'hr')",
            [':slug' => $slug],
        );
        $definitionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, 1, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $definitionId, ':u' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aTrainingVersion(string $slug): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[TrainingModules] ([Slug],[Title]) VALUES (:slug, N'Synthetic')",
            [':slug' => $slug],
        );
        $moduleId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[TrainingModuleVersions]
                ([TrainingModuleId],[VersionNumber],[PresentationManifestJson],[RequiredSlideCount],
                 [IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:m, 1, N'[]', 0, 1, SYSUTCDATETIME(), :u)",
            [':m' => $moduleId, ':u' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aPolicyVersion(string $slug): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[Policies] ([Slug],[Title]) VALUES (:slug, N'Synthetic')",
            [':slug' => $slug],
        );
        $policyId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 1, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aFormAssignment(int $formVersionId): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId])
             VALUES (:u, N'form', :v, :u2)",
            [':u' => $this->userId, ':v' => $formVersionId, ':u2' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aTrainingAssignment(int $trainingVersionId): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[TrainingModuleVersionId],[AllocatedByUserId])
             VALUES (:u, N'training', :v, :u2)",
            [':u' => $this->userId, ':v' => $trainingVersionId, ':u2' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aPolicyAssignment(int $policyVersionId): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[PolicyVersionId],[AllocatedByUserId])
             VALUES (:u, N'policy', :v, :u2)",
            [':u' => $this->userId, ':v' => $policyVersionId, ':u2' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function tokenOf(int $assignmentId): string
    {
        return trim($this->oneString(
            'SELECT ' . self::TOKEN_OUT . ' FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
            [':id' => $assignmentId],
        ));
    }

    /** An IN_PROGRESS ledger row, as an acquisition would write it. */
    private function acquire(string $actor, string $operation, string $key, ?string $hash = null): void
    {
        $this->exec(
            "INSERT INTO [onboarding].[OnboardingWriteIdempotency]
                ([ActorKey],[Operation],[IdempotencyKey],[RequestHash],[State],[PlatformOperationId])
             VALUES (:a, :o, :k, :h, N'IN_PROGRESS', NEWID())",
            [
                ':a' => $actor,
                ':o' => $operation,
                ':k' => $key,
                ':h' => $hash ?? str_repeat('a', 64),
            ],
        );
    }

    // ------------------------------------------------- optimistic concurrency

    /**
     * The coverage assertion, and the most valuable test here: a table added to
     * either schema next year has to be classified before this passes. Without
     * it, "ROWVERSION is on every administrator-editable table" is true of the
     * tables that existed the day somebody wrote it down and of no others.
     */
    public function test_every_table_is_row_versioned_append_only_or_single_writer(): void
    {
        $statement = $this->db()->query(
            "SELECT s.name + '.' + t.name AS [Name],
                    MAX(CASE WHEN c.system_type_id = 189 AND c.is_computed = 0 THEN 1 ELSE 0 END) AS [HasRowVersion]
               FROM sys.tables t
               JOIN sys.schemas s ON s.schema_id = t.schema_id
               LEFT JOIN sys.columns c ON c.object_id = t.object_id
              WHERE s.name IN (N'onboarding', N'onboarding_hr')
              GROUP BY s.name, t.name",
        );
        self::assertNotFalse($statement);

        /** @var array<string, bool> $live */
        $live = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);

            $name = $row['Name'] ?? null;
            self::assertIsString($name);

            $hasRowVersion = $row['HasRowVersion'] ?? null;
            self::assertIsNumeric($hasRowVersion);

            $live[$name] = (int) $hasRowVersion === 1;
        }

        $classified = array_merge(self::ROW_VERSIONED, self::APPEND_ONLY, self::SINGLE_WRITER);
        sort($classified);
        $actual = array_keys($live);
        sort($actual);

        self::assertSame(
            $classified,
            $actual,
            'A table in the onboarding schemas has no concurrency classification. Decide whether a second '
            . 'writer can reach it: give it a ROWVERSION, or add it to APPEND_ONLY or SINGLE_WRITER with '
            . 'the reason.',
        );

        foreach (self::ROW_VERSIONED as $table) {
            self::assertTrue($live[$table], $table . ' is classified ROW_VERSIONED but carries no ROWVERSION column.');
        }

        foreach (array_merge(self::APPEND_ONLY, self::SINGLE_WRITER) as $table) {
            self::assertFalse(
                $live[$table],
                $table . ' carries a ROWVERSION but is classified as needing none. One of the two is wrong.',
            );
        }
    }

    public function test_a_token_moves_on_every_update(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-token')));

        $before = $this->tokenOf($assignmentId);
        $this->exec(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'in_progress' WHERE [AssignmentId] = :id",
            [':id' => $assignmentId],
        );

        self::assertNotSame($before, $this->tokenOf($assignmentId));
    }

    /**
     * A write that changes nothing still moves the token, because SQL Server
     * stamps the row on any UPDATE that touches it rather than on a change of
     * value.
     *
     * The consequence is a real one for Phase 3: an HR user who opens a record
     * and presses Save without editing anything invalidates every other editor's
     * token. So a save should send only fields that actually changed, and a
     * no-op save should not reach the database at all. Recorded as a test
     * because it is counter-intuitive enough to be rediscovered as a bug.
     */
    public function test_a_token_moves_even_when_the_update_changes_nothing(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-noop')));
        $this->exec(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'in_progress' WHERE [AssignmentId] = :id",
            [':id' => $assignmentId],
        );

        $before = $this->tokenOf($assignmentId);
        $this->exec(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'in_progress' WHERE [AssignmentId] = :id",
            [':id' => $assignmentId],
        );

        self::assertNotSame(
            $before,
            $this->tokenOf($assignmentId),
            'A no-op update did not move the token, so a save that changes nothing is free. If this ever '
            . 'passes, the advice to send only changed fields can be relaxed.',
        );
    }

    /**
     * The lost update, which is the whole reason the column exists. Two HR users
     * open the same allocation; both hold the same token; the first writes and
     * the second's write matches nothing.
     *
     * Note what is asserted: not that the second write throws, but that it
     * affects ZERO ROWS. Optimistic concurrency does not fail loudly — a caller
     * that does not check the affected count will believe it saved.
     */
    public function test_the_second_of_two_editors_loses_and_the_first_change_stands(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-lost')));

        // Both administrators read the record, and so hold the same token.
        $tokenReadByBoth = $this->tokenOf($assignmentId);

        $first = $this->affected(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'in_progress'
              WHERE [AssignmentId] = :id AND [RowVersion] = " . self::TOKEN_IN,
            [':id' => $assignmentId, ':token' => $tokenReadByBoth],
        );
        self::assertSame(1, $first, 'The first writer, holding a current token, should have written.');

        $second = $this->affected(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'need_help'
              WHERE [AssignmentId] = :id AND [RowVersion] = " . self::TOKEN_IN,
            [':id' => $assignmentId, ':token' => $tokenReadByBoth],
        );
        self::assertSame(0, $second, 'The second writer held a stale token and should have matched no row.');

        self::assertSame(
            'in_progress',
            $this->oneString(
                'SELECT [Status] FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
                [':id' => $assignmentId],
            ),
            "The second writer's value reached the row, which is the lost update this column exists to stop.",
        );
    }

    /** Re-reading after losing is what a caller does next, and it must succeed. */
    public function test_the_loser_can_re_read_and_write_again(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-retry')));
        $stale = $this->tokenOf($assignmentId);

        $this->exec(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'in_progress' WHERE [AssignmentId] = :id",
            [':id' => $assignmentId],
        );

        self::assertSame(0, $this->affected(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'need_help'
              WHERE [AssignmentId] = :id AND [RowVersion] = " . self::TOKEN_IN,
            [':id' => $assignmentId, ':token' => $stale],
        ));

        self::assertSame(1, $this->affected(
            "UPDATE [onboarding].[Assignments] SET [Status] = N'need_help'
              WHERE [AssignmentId] = :id AND [RowVersion] = " . self::TOKEN_IN,
            [':id' => $assignmentId, ':token' => $this->tokenOf($assignmentId)],
        ));
    }

    /**
     * A token is not a version number, and must never be shown as one. The
     * counter is database-wide: writing to an unrelated table moves it, so two
     * successive edits of one record can leave a gap of thousands.
     */
    public function test_a_token_is_database_wide_and_not_a_per_record_counter(): void
    {
        $first = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-counter-a')));
        $second = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-counter-b')));

        $firstToken = $this->tokenOf($first);
        $secondToken = $this->tokenOf($second);

        self::assertNotSame(
            $firstToken,
            $secondToken,
            'Two freshly inserted rows shared a token, so the counter is not what this test assumes.',
        );
        self::assertSame(
            18,
            strlen($firstToken),
            'A token is expected to travel as 0x + sixteen hex digits.',
        );
    }

    /** A client cannot forge a token: the column is not writable. */
    public function test_a_client_cannot_supply_a_token(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-forge')));

        $this->assertRefusedBy('Cannot update a timestamp column', fn () => $this->exec(
            'UPDATE [onboarding].[Assignments] SET [RowVersion] = 0x0000000000000001 WHERE [AssignmentId] = :id',
            [':id' => $assignmentId],
        ));
    }

    public function test_a_token_cannot_be_supplied_on_insert(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-forge-insert')));

        $this->assertRefusedBy('Cannot insert an explicit value into a timestamp column', fn () => $this->exec(
            "INSERT INTO [onboarding].[FormDrafts] ([AssignmentId],[DraftDataJson],[RowVersion])
             VALUES (:a, N'{}', 0x0000000000000001)",
            [':a' => $assignmentId],
        ));
    }

    /**
     * The trap the hex transport form exists to avoid, kept as a test so that
     * nobody rediscovers it at the cost of an afternoon.
     *
     * PDO_SQLSRV binds a PHP string as NVARCHAR, so handing the eight raw bytes
     * straight back never works. HOW it fails depends on the bytes, which is the
     * part that makes it expensive to diagnose:
     *
     *   - bytes that are not valid UCS-2 fail inside the driver, before the
     *     server is reached, with `SQLSTATE[IMSSP] ... translating string for
     *     input param 1 to UCS-2`, which names a parameter number and nothing
     *     else — no column, no table, no hint the value was binary;
     *   - bytes that happen to translate reach the server and fail there with
     *     `Implicit conversion from data type nvarchar to timestamp`.
     *
     * So the same bug reports itself two different ways depending on the value
     * of a counter nobody controls. Both are asserted, because pinning the test
     * to one of them would make it fail on a Tuesday for no reason.
     *
     * Application code avoids both by binding through `SqlBinary`, which is the
     * platform's answer and predates this test — see TOKEN_IN above.
     */
    public function test_binding_a_raw_token_never_works_which_is_why_it_travels_as_hex(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-raw')));

        $raw = $this->one(
            'SELECT CONVERT(BINARY(8), [RowVersion]) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
            [':id' => $assignmentId],
        );
        self::assertIsString($raw);

        try {
            $this->exec(
                'UPDATE [onboarding].[Assignments] SET [Status] = N\'in_progress\'
                  WHERE [AssignmentId] = :id AND [RowVersion] = :token',
                [':id' => $assignmentId, ':token' => $raw],
            );
        } catch (PDOException $e) {
            $message = $e->getMessage();
            self::assertTrue(
                str_contains($message, 'UCS-2') || str_contains($message, 'timestamp'),
                'A raw token was refused, but by neither of the two known failures: ' . $message,
            );

            return;
        }

        self::fail('A raw eight-byte token was accepted as a parameter, so TOKEN_IN is no longer necessary.');
    }

    // ------------------------------------------------------- the write ledger

    public function test_the_same_key_cannot_be_acquired_twice(): void
    {
        $key = $this->aKey();
        $this->acquire('user:2', 'onboarding.invite', $key);

        $this->assertRefusedBy(
            'UX_onboarding_OnboardingWriteIdempotency_Actor_Op_Key',
            fn () => $this->acquire('user:2', 'onboarding.invite', $key),
        );
    }

    /**
     * A key belongs to the actor who chose it. Two people generating the same
     * key — which a client-side counter or a short random string makes likely —
     * must not block each other, or one person's retry silently swallows
     * another's first attempt.
     */
    public function test_two_actors_may_use_the_same_key(): void
    {
        $key = $this->aKey();
        $this->acquire('user:2', 'onboarding.invite', $key);
        $this->acquire('user:3', 'onboarding.invite', $key);

        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[OnboardingWriteIdempotency] WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        ));
    }

    /** And a key is scoped to one operation, so a retry cannot cross operations. */
    public function test_one_key_may_be_used_once_per_operation(): void
    {
        $key = $this->aKey();
        $this->acquire('user:2', 'onboarding.invite', $key);
        $this->acquire('user:2', 'onboarding.submitForm', $key);

        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[OnboardingWriteIdempotency] WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        ));
    }

    /**
     * The crash-recovery lookup. After a failure mid-write, the only handle on
     * what happened is the platform operation id, so it has to identify exactly
     * one attempt.
     */
    public function test_a_platform_operation_id_identifies_one_attempt(): void
    {
        $operationId = $this->oneString('SELECT CONVERT(NVARCHAR(36), NEWID())');
        $insert = "INSERT INTO [onboarding].[OnboardingWriteIdempotency]
                ([ActorKey],[Operation],[IdempotencyKey],[RequestHash],[State],[PlatformOperationId])
             VALUES (N'user:2', N'onboarding.invite', :k, REPLICATE('a',64), N'IN_PROGRESS', :p)";

        $this->exec($insert, [':k' => $this->aKey(), ':p' => $operationId]);

        $this->assertRefusedBy(
            'UX_onboarding_OnboardingWriteIdempotency_PlatformOperationId',
            fn () => $this->exec($insert, [':k' => $this->aKey(), ':p' => $operationId]),
        );
    }

    public function test_an_invented_ledger_state_is_refused(): void
    {
        $this->assertRefusedBy(
            'CK_onboarding_OnboardingWriteIdempotency_State',
            fn () => $this->exec(
                "INSERT INTO [onboarding].[OnboardingWriteIdempotency]
                    ([ActorKey],[Operation],[IdempotencyKey],[RequestHash],[State],[PlatformOperationId])
                 VALUES (N'user:2', N'onboarding.invite', :k, REPLICATE('a',64), N'PENDING', NEWID())",
                [':k' => $this->aKey()],
            ),
        );
    }

    /** The lifecycle a successful write follows, end to end. */
    public function test_a_completed_acquisition_records_what_it_produced(): void
    {
        $key = $this->aKey();
        $this->acquire('user:2', 'onboarding.invite', $key);

        $produced = $this->oneString('SELECT CONVERT(NVARCHAR(36), NEWID())');
        self::assertSame(1, $this->affected(
            "UPDATE [onboarding].[OnboardingWriteIdempotency]
                SET [State] = N'COMPLETED', [ResultPublicId] = :r, [UpdatedAtUtc] = SYSUTCDATETIME()
              WHERE [ActorKey] = N'user:2' AND [Operation] = N'onboarding.invite' AND [IdempotencyKey] = :k
                AND [State] = N'IN_PROGRESS'",
            [':r' => $produced, ':k' => $key],
        ));

        self::assertSame(strtolower($produced), strtolower($this->oneString(
            'SELECT CONVERT(NVARCHAR(36), [ResultPublicId]) FROM [onboarding].[OnboardingWriteIdempotency]
              WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        )));
    }

    /** And the lifecycle a failure follows, so a retry is not answered from a corpse. */
    public function test_a_failed_acquisition_records_why(): void
    {
        $key = $this->aKey();
        $this->acquire('user:2', 'onboarding.invite', $key);

        self::assertSame(1, $this->affected(
            "UPDATE [onboarding].[OnboardingWriteIdempotency]
                SET [State] = N'FAILED', [FailureReason] = N'synthetic', [UpdatedAtUtc] = SYSUTCDATETIME()
              WHERE [IdempotencyKey] = :k AND [State] = N'IN_PROGRESS'",
            [':k' => $key],
        ));

        self::assertSame('FAILED', $this->oneString(
            'SELECT [State] FROM [onboarding].[OnboardingWriteIdempotency] WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        ));
    }

    /**
     * A gap, stated rather than hidden: nothing stops a COMPLETED row from
     * carrying no result. A replay would then be answered "already done" with
     * nothing to hand back.
     *
     * This test asserts the hole exists, which is deliberate — if a CHECK
     * constraint is added later this test fails and names itself as the thing to
     * delete. Until then the invariant lives in whatever writes the row, and
     * Phase 3's store owes a test of its own.
     */
    public function test_the_database_does_not_yet_require_a_completed_row_to_carry_a_result(): void
    {
        $key = $this->aKey();
        $this->exec(
            "INSERT INTO [onboarding].[OnboardingWriteIdempotency]
                ([ActorKey],[Operation],[IdempotencyKey],[RequestHash],[State],[PlatformOperationId])
             VALUES (N'user:2', N'onboarding.invite', :k, REPLICATE('a',64), N'COMPLETED', NEWID())",
            [':k' => $key],
        );

        self::assertNull($this->one(
            'SELECT [ResultPublicId] FROM [onboarding].[OnboardingWriteIdempotency] WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        ));
    }

    /**
     * The race, with two real connections — the one test here that a single
     * connection cannot express.
     *
     * Two requests arrive at once with the same key. If an acquisition were a
     * SELECT followed by an INSERT, both would read "not found" and both would
     * write, and the double click the key exists to absorb would go through
     * twice. It is the unique index that prevents it: the second INSERT blocks
     * on the first while the first is uncommitted, and once the first resolves
     * the second either fails as a duplicate or proceeds.
     *
     * Asserted through a two-second LOCK_TIMEOUT, so the proof is that the
     * second writer WAITS rather than that it succeeds — waiting is what makes
     * a read-then-insert unnecessary. Both connections roll back.
     */
    public function test_a_concurrent_acquisition_of_the_same_key_waits_rather_than_duplicating(): void
    {
        $second = LiveAzureSqlSupport::connect();
        if ($second === null) {
            self::markTestSkipped('A second Azure SQL connection could not be opened.');
        }

        $key = $this->aKey('race');
        $insert = "INSERT INTO [onboarding].[OnboardingWriteIdempotency]
                ([ActorKey],[Operation],[IdempotencyKey],[RequestHash],[State],[PlatformOperationId])
             VALUES (N'user:2', N'onboarding.invite', '" . $key . "', REPLICATE('a',64), N'IN_PROGRESS', NEWID())";

        try {
            // The first request acquires the key, and has not committed yet.
            $this->exec($insert);

            $second->exec('SET LOCK_TIMEOUT 2000');
            $second->beginTransaction();

            $this->assertRefusedBy('Lock request time out period exceeded', static function () use ($second, $insert): void {
                $second->exec($insert);
            });

            $second->rollBack();

            // And once the first request is gone, the key is free again.
            $this->db()->rollBack();
            $this->db()->beginTransaction();

            $second->beginTransaction();
            $second->exec($insert);

            $check = $second->query(
                "SELECT COUNT(*) FROM [onboarding].[OnboardingWriteIdempotency] WHERE [IdempotencyKey] = '" . $key . "'",
            );
            self::assertNotFalse($check);

            $count = $check->fetchColumn();
            self::assertIsNumeric($count);
            self::assertSame(1, (int) $count);
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
        }
    }

    // --------------------------------------------------- the natural keys

    public function test_a_replayed_attempt_with_the_same_key_is_refused(): void
    {
        $assignmentId = $this->aTrainingAssignment($this->aTrainingVersion($this->uniqueSlug('syn-attempt')));
        $key = $this->aKey();

        $this->anAttempt($assignmentId, 1, $key);

        $this->assertRefusedBy(
            'UX_onboarding_Attempts_IdempotencyKey',
            fn () => $this->anAttempt($assignmentId, 2, $key),
        );
    }

    /**
     * The index is filtered on a non-null key, so attempts written without one
     * still work. That matters: the key comes from the client, and a client that
     * does not send one must not be refused its second attempt.
     */
    public function test_attempts_without_a_key_do_not_collide(): void
    {
        $assignmentId = $this->aTrainingAssignment($this->aTrainingVersion($this->uniqueSlug('syn-nokey')));

        $this->anAttempt($assignmentId, 1, null);
        $this->anAttempt($assignmentId, 2, null);

        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[Attempts] WHERE [AssignmentId] = :a',
            [':a' => $assignmentId],
        ));
    }

    /**
     * A key protects one assignment, not the person. A client reusing one key
     * across two assignments gets two writes — which is the right scope for a
     * retry, and the wrong thing to lean on for anything wider.
     */
    public function test_an_attempt_key_is_scoped_to_its_assignment(): void
    {
        $key = $this->aKey();
        $first = $this->aTrainingAssignment($this->aTrainingVersion($this->uniqueSlug('syn-scope-a')));
        $second = $this->aTrainingAssignment($this->aTrainingVersion($this->uniqueSlug('syn-scope-b')));

        $this->anAttempt($first, 1, $key);
        $this->anAttempt($second, 1, $key);

        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[Attempts] WHERE [IdempotencyKey] = :k',
            [':k' => $key],
        ));
    }

    public function test_a_replayed_submission_with_the_same_key_is_refused(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-resubmit'));
        $assignmentId = $this->aFormAssignment($versionId);
        $key = $this->aKey();

        $this->aSubmission($assignmentId, $versionId, 1, $key);

        $this->assertRefusedBy(
            'UX_onboarding_FormSubmissions_IdempotencyKey',
            fn () => $this->aSubmission($assignmentId, $versionId, 2, $key),
        );
    }

    /**
     * A correction is a new revision, and it needs a key of its own. The two
     * rules compose: the revision number stops the same revision arriving twice,
     * and the key stops one submit button press arriving twice.
     */
    public function test_a_correction_is_a_new_revision_with_its_own_key(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-correction'));
        $assignmentId = $this->aFormAssignment($versionId);

        $this->aSubmission($assignmentId, $versionId, 1, $this->aKey());
        $this->aSubmission($assignmentId, $versionId, 2, $this->aKey());

        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[FormSubmissions] WHERE [AssignmentId] = :a',
            [':a' => $assignmentId],
        ));
    }

    /**
     * A double-clicked "save draft" must not make two drafts. The unique
     * constraint means the write has to be a MERGE or an insert whose duplicate
     * is caught — never "SELECT, then INSERT if absent", which races.
     */
    public function test_a_draft_exists_once_per_assignment(): void
    {
        $assignmentId = $this->aFormAssignment($this->aFormVersion($this->uniqueSlug('syn-draft')));

        $this->exec(
            "INSERT INTO [onboarding].[FormDrafts] ([AssignmentId],[DraftDataJson]) VALUES (:a, N'{}')",
            [':a' => $assignmentId],
        );

        $this->assertRefusedBy('UQ_onboarding_FormDrafts_Assignment', fn () => $this->exec(
            "INSERT INTO [onboarding].[FormDrafts] ([AssignmentId],[DraftDataJson]) VALUES (:a, N'{}')",
            [':a' => $assignmentId],
        ));
    }

    /** The same, for the slide progress a presentation writes on every slide. */
    public function test_progress_exists_once_per_assignment(): void
    {
        $versionId = $this->aTrainingVersion($this->uniqueSlug('syn-progress'));
        $assignmentId = $this->aTrainingAssignment($versionId);

        $insert = "INSERT INTO [onboarding].[PresentationProgress]
                ([AssignmentId],[TrainingModuleVersionId],[SlideIdsJson]) VALUES (:a, :v, N'[]')";
        $this->exec($insert, [':a' => $assignmentId, ':v' => $versionId]);

        $this->assertRefusedBy(
            'UQ_onboarding_PresentationProgress_Assignment',
            fn () => $this->exec($insert, [':a' => $assignmentId, ':v' => $versionId]),
        );
    }

    /**
     * Acknowledging the same policy version twice is refused by the natural key,
     * (UserId, PolicyVersionId), rather than by the [IdempotencyKey] column the
     * table also carries — which has no unique index on it and therefore
     * promises nothing on its own. Worth knowing before somebody relies on it.
     */
    public function test_an_acknowledgement_is_recorded_once_per_version(): void
    {
        $versionId = $this->aPolicyVersion($this->uniqueSlug('syn-ack'));
        $assignmentId = $this->aPolicyAssignment($versionId);

        $insert = "INSERT INTO [onboarding].[PolicyAcknowledgements]
                ([AssignmentId],[UserId],[PolicyVersionId],[DeclarationText],[IdempotencyKey])
             VALUES (:a, :u, :v, N'I have read it', :k)";

        $this->exec($insert, [':a' => $assignmentId, ':u' => $this->userId, ':v' => $versionId, ':k' => $this->aKey()]);

        // A different key, the same person and version: still one acknowledgement.
        $this->assertRefusedBy(
            'UQ_onboarding_PolicyAcknowledgements_UserVersion',
            fn () => $this->exec(
                $insert,
                [':a' => $assignmentId, ':u' => $this->userId, ':v' => $versionId, ':k' => $this->aKey()],
            ),
        );
    }

    private function anAttempt(int $assignmentId, int $number, ?string $key): void
    {
        $this->exec(
            "INSERT INTO [onboarding].[Attempts]
                ([AssignmentId],[AttemptNumber],[QuestionSnapshotJson],[IdempotencyKey])
             VALUES (:a, :n, N'[]', :k)",
            [':a' => $assignmentId, ':n' => $number, ':k' => $key],
        );
    }

    private function aSubmission(int $assignmentId, int $formVersionId, int $revision, ?string $key): void
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormSubmissions]
                ([AssignmentId],[UserId],[FormDefinitionVersionId],[RevisionNumber],[SubmittedDataJson],[IdempotencyKey])
             VALUES (:a, :u, :v, :r, N'{\"a\":1}', :k)",
            [':a' => $assignmentId, ':u' => $this->userId, ':v' => $formVersionId, ':r' => $revision, ':k' => $key],
        );
    }
}
