<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingCompletionFeedRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;

/**
 * Phase 6 — the completion feed's query against `tricab-mel`. Read only: it
 * writes nothing, and checks the feed against a count taken straight from the
 * event table, so whatever is there today is what it is held to.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingCompletionFeedRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AzureSqlOnboardingCompletionFeedRepository $feed;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }
        $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[audit].[OnboardingStatusEvents]', N'U') IS NULL THEN 0 ELSE 1 END");
        if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
            self::markTestSkipped('The onboarding schema is not applied to this database.');
        }
        $this->pdo = $pdo;
        $this->feed = new AzureSqlOnboardingCompletionFeedRepository(new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test')));
    }

    public function test_the_feed_is_every_settled_completion_event_in_sequence_order(): void
    {
        $settled = new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC'));
        $counted = $this->pdo->query(sprintf(
            "SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents] se
             JOIN [onboarding].[Assignments] a ON a.[PublicId] = se.[AssignmentPublicId]
             WHERE (se.[ToStatus] = N'completed' OR se.[FromStatus] = N'completed')
               AND se.[CreatedAtUtc] < CONVERT(DATETIMEOFFSET, '%s', 127)",
            $settled->format('Y-m-d\TH:i:s\Z'),
        ));
        $expected = $counted === false ? 0 : (int) $counted->fetchColumn();

        $entries = $this->feed->after(0, 10_000, $settled);

        self::assertCount($expected, $entries);
        $previous = 0;
        foreach ($entries as $entry) {
            self::assertGreaterThan($previous, $entry->sequence);
            $previous = $entry->sequence;
            self::assertContains($entry->type, [CompletionFeedEntry::TYPE_RECORDED, CompletionFeedEntry::TYPE_REOPENED]);
            self::assertContains($entry->itemType, ['training', 'policy', 'form']);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $entry->userPublicId);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $entry->occurredAtUtc);
            self::assertNotSame('', $entry->itemSlug);
        }
    }

    public function test_a_cursor_resumes_after_itself_and_the_limit_holds(): void
    {
        $settled = new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC'));
        $all = $this->feed->after(0, 10_000, $settled);
        if (count($all) < 2) {
            self::markTestSkipped('Two completion events are needed.');
        }

        self::assertCount(1, $this->feed->after(0, 1, $settled));
        self::assertSame($all[1]->sequence, $this->feed->after($all[0]->sequence, 1, $settled)[0]->sequence);
    }
}
