<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\LegacyMigration\Persistence\InMemoryLegacyMigrationStore;
use TriCab\Api\Shared\Persistence\NoOpApplicationTransaction;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\LegacyMigration\Application\Service\LegacyMigrationEngine;
use TriCab\Core\LegacyMigration\Module\DefaultModuleRegistry;
use TriCab\Core\LegacyMigration\Plugin\StagingOnlyExecutor;

final class LegacyMigrationEngineTest extends TestCase
{
    public function test_executor_refuses_production_writes(): void
    {
        self::assertFalse((new StagingOnlyExecutor())->writesProductionTables());
    }

    public function test_pipeline_is_resumable_and_audited(): void
    {
        $engine = new LegacyMigrationEngine(
            new InMemoryLegacyMigrationStore(),
            new DefaultModuleRegistry(),
            new NoOpApplicationTransaction(),
        );
        $actor = new ActorContext(1, '00000000-0000-4000-8000-000000000001', 'ops@example.invalid');
        $batch = $engine->createBatch($actor, 'Employees harness', 'employees');
        $id = $batch['publicId'];
        $engine->extract($actor, $id);
        $engine->normalize($actor, $id);
        $engine->validate($actor, $id);
        $engine->preview($actor, $id);
        $approved = $engine->approve($actor, $id);
        self::assertSame('approved', $approved['statusCode']);
        $executed = $engine->execute($actor, $id);
        self::assertSame('completed', $executed['statusCode']);
        $logs = $engine->logs($id);
        $actions = array_map(static fn (array $row): string => (string) $row['actionCode'], $logs);
        self::assertContains('approved', $actions);
        self::assertContains('execution-completed', $actions);
        $extractLogs = array_values(array_filter(
            $logs,
            static fn (array $row): bool => ($row['actionCode'] ?? '') === 'extraction-completed',
        ));
        self::assertNotSame([], $extractLogs);
        self::assertArrayHasKey('durationMs', $extractLogs[0]);
    }

    public function test_conflict_decisions_survive_revalidate(): void
    {
        $store = new InMemoryLegacyMigrationStore();
        $engine = new LegacyMigrationEngine(
            $store,
            new DefaultModuleRegistry(),
            new NoOpApplicationTransaction(),
        );
        $actor = new ActorContext(1, '00000000-0000-4000-8000-000000000001', 'ops@example.invalid');
        $batch = $engine->createBatch($actor, 'Users harness', 'users');
        $id = $batch['publicId'];
        $engine->extract($actor, $id);
        $engine->normalize($actor, $id);
        $engine->validate($actor, $id);
        $conflicts = $engine->conflicts($id);
        self::assertNotSame([], $conflicts);
        $conflictId = (string) $conflicts[0]['publicId'];
        $engine->resolveConflict($actor, $conflictId, 'skip');
        $engine->validate($actor, $id);
        $again = $engine->conflicts($id);
        $matched = array_values(array_filter(
            $again,
            static fn (array $c): bool => ($c['publicId'] ?? '') === $conflictId,
        ));
        self::assertNotSame([], $matched);
        self::assertSame('skip', $matched[0]['decisionCode']);
    }

    public function test_user_workspace_never_includes_passwords(): void
    {
        $engine = new LegacyMigrationEngine(
            new InMemoryLegacyMigrationStore(),
            new DefaultModuleRegistry(),
            new NoOpApplicationTransaction(),
        );
        $actor = new ActorContext(1, '00000000-0000-4000-8000-000000000001', 'ops@example.invalid');
        $batch = $engine->createBatch($actor, 'Users harness', 'users');
        $engine->extract($actor, $batch['publicId']);
        $engine->normalize($actor, $batch['publicId']);
        $encoded = json_encode($engine->userWorkspace($batch['publicId']), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('user_pass', $encoded);
        self::assertStringNotContainsString('password', strtolower($encoded));
    }
}
