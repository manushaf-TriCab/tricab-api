<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAdminAuditLog;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAssignmentLocator;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingContentRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingTrainingRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Phase 4.4 against `tricab-mel`, inside a transaction that is always rolled
 * back. Slugs are `syn-` and random, so they collide with nothing; the people
 * given assignments are accounts with no induction record.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingContentRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private AzureSqlOnboardingContentRepository $content;
    private AuthorOnboardingContentService $service;
    private AzureSqlOnboardingTrainingRepository $training;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[audit].[OnboardingAdminEvents]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The HR audit table is needed.');
            }
            $rows = $pdo->query(
                'SELECT TOP 4 u.[UserId] FROM [identity].[Users] u
                 WHERE NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[UserId] = u.[UserId])
                 ORDER BY u.[UserId]',
            );
            $ids = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }
        if (count($ids) < 4) {
            self::markTestSkipped('Four users without an induction record are needed.');
        }
        foreach ($ids as $id) {
            self::assertIsNumeric($id);
            $this->users[] = (int) $id;
        }

        $this->pdo = $pdo;
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test'));
        $this->content = new AzureSqlOnboardingContentRepository($connection);
        $this->service = new AuthorOnboardingContentService($this->content, new AzureSqlOnboardingAdminAuditLog($connection), new UuidV4Generator());
        $this->training = new AzureSqlOnboardingTrainingRepository($connection, new AzureSqlOnboardingAssignmentLocator($connection));

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public function test_a_module_round_trips_publishes_and_is_then_frozen(): void
    {
        [$detail, $draft] = $this->newModule();
        $saved = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody('First'));

        self::assertSame([['id' => 'intro', 'title' => 'Why forklifts are dangerous'], ['id' => 'zones', 'title' => 'Pedestrian zones']], $saved->content->slides);
        self::assertSame(['First', 'Second'], array_column($saved->content->questions, 'prompt'), 'ordinal order');
        self::assertSame('b', $saved->content->questions[0]['correctOptionKey']);
        self::assertSame(2, $this->number('SELECT [RequiredSlideCount] FROM [onboarding].[TrainingModuleVersions] WHERE [PublicId] = :p', [':p' => $saved->publicId]));

        $published = $this->service->publish($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);
        self::assertSame('live', $published['version']->state);
        self::assertSame($this->users[0], $this->number('SELECT [PublishedByUserId] FROM [onboarding].[TrainingModuleVersions] WHERE [PublicId] = :p', [':p' => $saved->publicId]));

        try {
            $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $published['version']->rowVersion, self::moduleBody('Rewritten'));
            self::fail('A published version was rewritten.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_VERSION_PUBLISHED, $refused->reason);
        }

        // The repository's own guard, beneath the service's: a save built on
        // the draft as it was read before publishing still matches nothing.
        try {
            $this->content->saveDraft($detail->item, $saved, $published['version']->rowVersion, $saved->content, [], $this->actor());
            self::fail('The repository wrote to a published version.');
        } catch (ContentAuthoringNotPermitted $refused) {
            self::assertSame(ContentAuthoringNotPermitted::REASON_VERSION_PUBLISHED, $refused->reason);
        }
        self::assertSame(['First', 'Second'], $this->column('SELECT q.[Prompt] FROM [onboarding].[Questions] q JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleVersionId] = q.[TrainingModuleVersionId] WHERE v.[PublicId] = :p ORDER BY q.[Ordinal]', [':p' => $saved->publicId]));

        $events = $this->column('SELECT [EventType] FROM [audit].[OnboardingAdminEvents] WHERE [ActorUserId] = :a AND [CreatedAtUtc] > DATEADD(MINUTE, -5, SYSUTCDATETIME()) ORDER BY [OnboardingAdminEventId]', [':a' => $this->users[0]]);
        self::assertSame(['content.item_created', 'content.draft_saved', 'content.published', 'content.refused'], array_slice($events, -4));
    }

    public function test_a_trainee_given_an_authored_module_never_receives_its_answer_key(): void
    {
        [$itemPublicId, $v1] = $this->publishedModule();
        $trainee = $this->users[1];
        $assignment = $this->assign($trainee, $v1, 'not_started');

        $detail = $this->training->findTraining($trainee, $assignment);
        self::assertNotNull($detail);
        $wire = json_encode($detail->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('Why forklifts are dangerous', $wire, 'the authored slide reached the trainee');
        self::assertStringNotContainsString('correctOptionKey', $wire);
        self::assertStringNotContainsString('A driver who has not seen you', $wire, 'nor the explanation');
        unset($itemPublicId);
    }

    public function test_a_superseding_version_moves_unfinished_work_only(): void
    {
        [$itemPublicId, $v1] = $this->publishedModule();
        [, $moving, $finished, $leaving] = $this->users;
        $movingFrom = $this->assign($moving, $v1, 'in_progress', '2026-11-30');
        $this->assign($finished, $v1, 'completed');
        $this->assign($leaving, $v1, 'in_progress');
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [DepartedAtUtc], [DepartureRecordedByUserId]) VALUES (:u, DATEADD(DAY, -1, SYSUTCDATETIME()), :by)',
            [':u' => $leaving, ':by' => $this->users[0]],
        );

        $draft = $this->service->createDraft($this->actor(), 'training', $itemPublicId);
        self::assertSame(2, $draft->versionNumber);
        self::assertSame(['First', 'Second'], array_column($draft->content->questions, 'prompt'), 'copied from the live version');
        $saved = $this->service->saveDraft($this->actor(), 'training', $itemPublicId, $draft->publicId, $draft->rowVersion, self::moduleBody('Reworded'));
        $result = $this->service->publish($this->actor(), 'training', $itemPublicId, $saved->publicId, $saved->rowVersion, true);

        self::assertSame(1, $result['retiredVersionNumber']);
        self::assertSame(1, $result['reissuedCount']);

        $old = $this->row('SELECT CONVERT(VARCHAR(33), [WithdrawnAtUtc], 127) AS [W], [WithdrawalReason] AS [R], [Status] AS [S] FROM [onboarding].[Assignments] WHERE [PublicId] = :p', [':p' => $movingFrom]);
        self::assertNotSame('', self::str($old['W']));
        self::assertStringContainsString('Replaced by version 2', self::str($old['R']));
        self::assertSame('in_progress', $old['S'], 'the old assignment keeps the status it had');

        $new = $this->row(
            'SELECT a.[Status] AS [S], CONVERT(VARCHAR(10), a.[DueDate], 23) AS [D] FROM [onboarding].[Assignments] a
             JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
             WHERE a.[UserId] = :u AND v.[PublicId] = :v AND a.[WithdrawnAtUtc] IS NULL',
            [':u' => $moving, ':v' => $saved->publicId],
        );
        self::assertSame(['S' => 'not_started', 'D' => '2026-11-30'], $new);

        foreach ([$finished, $leaving] as $untouched) {
            self::assertSame(1, $this->number(
                'SELECT COUNT(*) FROM [onboarding].[Assignments] a JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
                 WHERE a.[UserId] = :u AND v.[PublicId] = :v AND a.[WithdrawnAtUtc] IS NULL',
                [':u' => $untouched, ':v' => $v1],
            ));
        }

        self::assertNotSame('', $this->text('SELECT CONVERT(VARCHAR(33), [RetiredAtUtc], 127) FROM [onboarding].[TrainingModuleVersions] WHERE [PublicId] = :p', [':p' => $v1]));
        self::assertSame(1, $this->number("SELECT COUNT(*) FROM [audit].[OnboardingAdminEvents] WHERE [EventType] = N'allocation.reissued' AND [SubjectUserId] = :u AND [CreatedAtUtc] > DATEADD(MINUTE, -5, SYSUTCDATETIME())", [':u' => $moving]));
    }

    /**
     * Work order §9, "policy version carry-forward preserves old
     * acknowledgements". Publishing version 2 as a real change moves the person
     * who had not acknowledged, and leaves the person who had exactly where they
     * were: their acknowledgement row, its wording and its time untouched, still
     * pointing at version 1, and no acknowledgement of version 2 invented for them.
     */
    public function test_a_new_policy_version_leaves_an_existing_acknowledgement_untouched(): void
    {
        [, $acknowledged, $reading] = $this->users;
        $body = static fn (string $wording): array => [
            'blocks' => [['type' => 'paragraph', 'text' => $wording]],
            'declaration' => 'I have read the ' . $wording . ' policy.',
        ];
        $policy = $this->service->createItem($this->actor(), 'policy', ['slug' => 'syn-policy-' . bin2hex(random_bytes(4)), 'title' => 'Synthetic conduct policy']);
        $v1 = $this->firstVersion($policy);
        $v1 = $this->service->saveDraft($this->actor(), 'policy', $policy->item->publicId, $v1->publicId, $v1->rowVersion, $body('first'));
        $this->service->publish($this->actor(), 'policy', $policy->item->publicId, $v1->publicId, $v1->rowVersion, false);

        foreach ([[$acknowledged, 'completed'], [$reading, 'in_progress']] as [$userId, $status]) {
            $this->exec(
                "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [PolicyVersionId], [Status], [AllocatedByUserId])
                 SELECT :u, N'policy', v.[PolicyVersionId], :s, :by FROM [onboarding].[PolicyVersions] v WHERE v.[PublicId] = :v",
                [':u' => $userId, ':s' => $status, ':by' => $this->users[0], ':v' => $v1->publicId],
            );
        }
        $this->exec(
            "INSERT INTO [onboarding].[PolicyAcknowledgements] ([AssignmentId], [UserId], [PolicyVersionId], [AcknowledgedAtUtc], [DeclarationText])
             SELECT a.[AssignmentId], a.[UserId], a.[PolicyVersionId], '2026-09-01T00:00:00', N'I have read the first policy.'
             FROM [onboarding].[Assignments] a JOIN [onboarding].[PolicyVersions] v ON v.[PolicyVersionId] = a.[PolicyVersionId]
             WHERE a.[UserId] = :u AND v.[PublicId] = :v",
            [':u' => $acknowledged, ':v' => $v1->publicId],
        );

        $v2 = $this->service->createDraft($this->actor(), 'policy', $policy->item->publicId);
        $v2 = $this->service->saveDraft($this->actor(), 'policy', $policy->item->publicId, $v2->publicId, $v2->rowVersion, $body('second'));
        $result = $this->service->publish($this->actor(), 'policy', $policy->item->publicId, $v2->publicId, $v2->rowVersion, true);
        self::assertSame(1, $result['reissuedCount'], 'only the unfinished reader moves');

        $ack = $this->row(
            "SELECT v.[PublicId] AS [V], k.[DeclarationText] AS [T], CONVERT(VARCHAR(19), k.[AcknowledgedAtUtc], 126) AS [At]
             FROM [onboarding].[PolicyAcknowledgements] k JOIN [onboarding].[PolicyVersions] v ON v.[PolicyVersionId] = k.[PolicyVersionId]
             WHERE k.[UserId] = :u",
            [':u' => $acknowledged],
        );
        self::assertSame(strtolower($v1->publicId), strtolower(self::str($ack['V'])));
        self::assertSame('I have read the first policy.', $ack['T']);
        self::assertSame('2026-09-01T00:00:00', $ack['At']);

        $live = static fn (string $version): string => "SELECT COUNT(*) FROM [onboarding].[Assignments] a JOIN [onboarding].[PolicyVersions] v ON v.[PolicyVersionId] = a.[PolicyVersionId]
             WHERE a.[UserId] = :u AND v.[PublicId] = '{$version}' AND a.[WithdrawnAtUtc] IS NULL";
        self::assertSame(1, $this->number($live($v1->publicId) . " AND a.[Status] = N'completed'", [':u' => $acknowledged]), 'still complete, on version 1');
        self::assertSame(0, $this->number($live($v2->publicId), [':u' => $acknowledged]), 'not given version 2');
        self::assertSame(1, $this->number($live($v2->publicId) . " AND a.[Status] = N'not_started'", [':u' => $reading]), 'the reader starts version 2');
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM [onboarding].[PolicyAcknowledgements] WHERE [UserId] IN (:a, :r)', [':a' => $acknowledged, ':r' => $reading]), 'no acknowledgement is carried to, or invented for, version 2');
        self::assertNotSame('', $this->text('SELECT CONVERT(VARCHAR(33), [SupersededAtUtc], 127) FROM [onboarding].[PolicyVersions] WHERE [PublicId] = :p', [':p' => $v1->publicId]));
    }

    public function test_a_payroll_form_writes_its_classifications_and_a_policy_its_wording(): void
    {
        $form = $this->service->createItem($this->actor(), 'form', ['slug' => 'syn-form-' . bin2hex(random_bytes(4)), 'title' => 'Synthetic bank details', 'accessGroup' => 'payroll']);
        $draft = $this->firstVersion($form);
        $this->service->saveDraft($this->actor(), 'form', $form->item->publicId, $draft->publicId, $draft->rowVersion, ['fields' => [
            ['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text', 'required' => true, 'dataClass' => DataClass::PAYROLL],
            ['path' => 'confirm', 'label' => 'Correct', 'type' => 'checkbox', 'dataClass' => DataClass::ORDINARY_HR],
        ]]);

        self::assertSame(
            ['bank.bsb:3:' . $this->users[0], 'confirm:2:' . $this->users[0]],
            $this->column(
                "SELECT c.[FieldPath] + N':' + CONVERT(NVARCHAR(3), c.[RetentionClassId]) + N':' + CONVERT(NVARCHAR(20), c.[ClassifiedByUserId])
                 FROM [onboarding_hr].[FieldClassifications] c JOIN [onboarding].[FormDefinitionVersions] v ON v.[FormDefinitionVersionId] = c.[FormDefinitionVersionId]
                 WHERE v.[PublicId] = :p ORDER BY c.[FieldPath]",
                [':p' => $draft->publicId],
            ),
        );

        $policy = $this->service->createItem($this->actor(), 'policy', ['slug' => 'syn-policy-' . bin2hex(random_bytes(4)), 'title' => 'Synthetic policy']);
        $policyDraft = $this->firstVersion($policy);
        $saved = $this->service->saveDraft($this->actor(), 'policy', $policy->item->publicId, $policyDraft->publicId, $policyDraft->rowVersion, [
            'blocks' => [['type' => 'list', 'style' => 'lower-roman', 'start' => 3, 'items' => ['Report hazards']]],
            'declaration' => 'I will report hazards.',
            'effectiveFrom' => '2026-10-01',
        ]);
        self::assertSame('I will report hazards.', $saved->content->declaration);
        self::assertSame('2026-10-01', $saved->content->effectiveFrom);
        self::assertSame('lower-roman', $saved->content->blocks[0]['style']);

        $this->service->publish($this->actor(), 'policy', $policy->item->publicId, $saved->publicId, $saved->rowVersion, false);
        $listed = array_values(array_filter($this->content->listItems(), static fn ($item): bool => $item->publicId === $policy->item->publicId));
        self::assertSame(1, $listed[0]->liveVersionNumber);
        self::assertNull($listed[0]->draftVersionNumber);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{0: ContentItemDetail, 1: ContentVersionRecord}
     */
    private function newModule(): array
    {
        $detail = $this->service->createItem($this->actor(), 'training', ['slug' => 'syn-module-' . bin2hex(random_bytes(4)), 'title' => 'Synthetic forklift awareness']);

        return [$detail, $this->firstVersion($detail)];
    }

    /**
     * @return array{0: string, 1: string} item and live version public ids
     */
    private function publishedModule(): array
    {
        [$detail, $draft] = $this->newModule();
        $saved = $this->service->saveDraft($this->actor(), 'training', $detail->item->publicId, $draft->publicId, $draft->rowVersion, self::moduleBody('First'));
        $this->service->publish($this->actor(), 'training', $detail->item->publicId, $saved->publicId, $saved->rowVersion, false);

        return [$detail->item->publicId, $saved->publicId];
    }

    private function firstVersion(ContentItemDetail $detail): ContentVersionRecord
    {
        $version = $this->content->findVersion($detail->item, $detail->versions[0]->publicId);
        self::assertNotNull($version);

        return $version;
    }

    private function assign(int $userId, string $versionPublicId, string $status, ?string $dueDate = null): string
    {
        $statement = $this->db()->prepare(
            "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [TrainingModuleVersionId], [Status], [AllocatedByUserId], [DueDate])
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId])
             SELECT :u, N'training', v.[TrainingModuleVersionId], :s, :by, TRY_CONVERT(DATE, :d, 23)
             FROM [onboarding].[TrainingModuleVersions] v WHERE v.[PublicId] = :v",
        );
        $statement->execute([':u' => $userId, ':s' => $status, ':by' => $this->users[0], ':d' => $dueDate, ':v' => $versionPublicId]);

        return strtolower(self::str($statement->fetchColumn()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function moduleBody(string $firstPrompt): array
    {
        return [
            'slides' => [['id' => 'intro', 'title' => 'Why forklifts are dangerous'], ['id' => 'zones', 'title' => 'Pedestrian zones']],
            'passMarkPercent' => 100,
            'questions' => [
                [
                    'prompt' => $firstPrompt,
                    'options' => [['key' => 'a', 'label' => 'Wave and keep walking'], ['key' => 'b', 'label' => 'Stop and wait to be seen']],
                    'correctOptionKey' => 'b',
                    'correctReason' => 'A driver who has not seen you cannot avoid you.',
                ],
                [
                    'prompt' => 'Second',
                    'options' => [['key' => 'a', 'label' => 'Yes'], ['key' => 'b', 'label' => 'No']],
                    'correctOptionKey' => 'a',
                    'correctReason' => null,
                ],
            ],
        ];
    }

    private function actor(): ActorContext
    {
        return new ActorContext($this->users[0], 'hr-public-id', 'hr@example.test', 'test-correlation');
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function row(string $sql, array $params): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, $sql);

        /** @var array<string, mixed> */
        return $row;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return array_values(array_map(self::str(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $params */
    private function text(string $sql, array $params = []): string
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return self::str($statement->fetchColumn());
    }

    /** @param array<string, mixed> $params */
    private function number(string $sql, array $params = []): int
    {
        $value = $this->text($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function str(mixed $value): string
    {
        self::assertTrue($value === null || is_scalar($value));

        return (string) $value;
    }

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }
}
