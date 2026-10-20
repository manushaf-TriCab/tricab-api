<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqAnalyticsSource;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;

/**
 * The analytics source reads the source's columns as tc-web's formulas
 * expect: aliased statuses, the owner-branch fallback, both timestamp shapes,
 * and per-session entry counts with the "unchanged" rule.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class AzureSqlRfqAnalyticsSourceTest extends TestCase
{
    private PDO $pdo;
    private AzureSqlRfqAnalyticsSource $source;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        $this->source = new AzureSqlRfqAnalyticsSource(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
    }

    public function testDraftsReadAsTheFormulasExpect(): void
    {
        $this->draft('a', "N'pending'", "N'VIC'", 'NULL', "N' Zoe@TriCab.com '", "N'2026-07-11 20:52:03'", "N''");
        $this->draft('b', "N'push_failed'", "N''", "N'{\"owner_branch\": \"nsw\"}'", 'NULL', 'NULL', "N'2026-09-01T00:00:00+00:00'");
        $this->draft('c', "N''", 'NULL', "N'not json'", "N''", 'NULL', 'NULL');

        $drafts = [];
        foreach ($this->source->drafts() as $draft) {
            $drafts[$draft->id] = $draft;
        }
        ksort($drafts);
        self::assertSame(['a', 'b', 'c'], array_map(static fn (AnalyticsDraft $d): string => trim($d->id), array_values($drafts)));
        [$a, $b, $c] = array_values($drafts);

        self::assertSame('new', $a->status, 'pending is new');
        self::assertSame('failed', $b->status, 'push_failed is failed');
        self::assertSame('new', $c->status, 'blank is new');
        self::assertSame('zoe@tricab.com', $a->assigneeEmail);
        self::assertSame('', $b->assigneeEmail);
        self::assertSame('VIC', $a->branch);
        self::assertSame('NSW', $b->branch, 'falls back to email_meta.owner_branch');
        self::assertSame('', $c->branch, 'unreadable email_meta is no branch');
        self::assertSame('2026-07-11T20:52:03+00:00', $a->dueAt?->format(DATE_ATOM), 'the July due_at shape');
        self::assertFalse($a->archived, 'an empty archived_at is not archived');
        self::assertTrue($b->archived);
        self::assertSame('2026-10-01T00:00:00+00:00', $a->createdAt?->format(DATE_ATOM));
    }

    public function testEventsAreOnlyTheCountedTypes(): void
    {
        $this->draft('a', "N'new'", 'NULL', 'NULL', 'NULL', 'NULL', 'NULL');
        foreach ([['claimed', '2026-10-02T00:00:00+00:00'], ['sync_succeeded', '2026-10-03T00:00:00Z'], ['comment', '2026-10-02T00:00:00+00:00'], ['claimed', 'garbage']] as $i => [$type, $at]) {
            $this->pdo->exec("INSERT INTO [rfq].[rfq_draft_activity] ([id], [draft_id], [created_at], [event_type])
                VALUES ('" . str_pad((string) $i, 32, '0') . "', '" . str_pad('a', 32) . "', N'{$at}', N'{$type}')");
        }

        $events = $this->source->events();
        $types = array_map(static fn ($e): string => $e->type, $events);
        sort($types);
        self::assertSame(['claimed', 'sync_succeeded'], $types, 'comments are not counted, and an unreadable time is dropped');
    }

    public function testReviewsCountEntriesPerSession(): void
    {
        $this->session('s1', 'd1', 0);
        $this->session('s2', '', 1);
        $this->session('s3', 'd1', 0);
        $entry = function (string $session, int $i, ?string $system, ?string $final, ?string $reason, ?float $confidence): void {
            $q = static fn (?string $v): string => $v === null ? 'NULL' : "N'{$v}'";
            $this->pdo->exec("INSERT INTO [rfq].[rfq_entries] ([id], [session_id], [created_at], [system_product_code], [final_product_code],
                [system_status], [final_status], [correction_reason], [system_confidence], [full_payload])
                VALUES ('" . str_pad("{$session}{$i}", 32, '0') . "', '" . str_pad($session, 32) . "', N'2026-10-01T00:00:00+00:00', {$q($system)}, {$q($final)},
                N'matched', N'matched', {$q($reason)}, " . ($confidence === null ? 'NULL' : (string) $confidence) . ", N'{}')");
        };
        $entry('s1', 1, 'TC1', 'TC1', null, 0.9);         // unchanged, high
        $entry('s1', 2, 'TC1', 'tc1', '  ', 0.85);        // unchanged: codes compare as the store compares, blank reason; high at the boundary
        $entry('s1', 3, 'TC1', 'TC2', ' wrong size ', 0.6); // changed with a reason; medium at the boundary
        $entry('s1', 4, null, 'TC3', null, 0.2);          // changed, no reason; low
        $entry('s1', 5, 'TC4', 'TC4', 'wrong size', null); // a reason makes it changed; unknown

        $reviews = [];
        foreach ($this->source->reviews() as $review) {
            $reviews[trim($review->sessionId)] = $review;
        }
        ksort($reviews);
        self::assertSame(['s1', 's2', 's3'], array_keys($reviews));
        $s1 = $reviews['s1'];
        self::assertInstanceOf(AnalyticsReview::class, $s1);
        self::assertSame([5, 2, 2, 2, 1, 1, 1], [$s1->entries, $s1->unchanged, $s1->withReason, $s1->highConfidence, $s1->mediumConfidence, $s1->lowConfidence, $s1->unknownConfidence]);
        self::assertSame(['wrong size' => 2], $s1->reasons, 'trimmed and grouped');
        self::assertSame('d1', trim($s1->draftId));
        self::assertSame(0, $reviews['s3']->entries, 'a session with no entries is still read');
        self::assertTrue($reviews['s2']->legacyUnlinked);
    }

    public function testPeopleAreNamedThroughTheQueueMapping(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId]) OUTPUT INSERTED.[UserId] VALUES (?, ?, ?, 1)');
        $insert->execute(['zoe@tricab.com', 'zoe@tricab.com', 'Zoe Sales']);
        $zoe = (int) $insert->fetchColumn();
        $insert->closeCursor();

        self::assertSame(['zoe@tricab.com' => ['userId' => $zoe, 'name' => 'Zoe Sales']], $this->source->people(['zoe@tricab.com', 'ghost@tricab.com']));
        self::assertSame([], $this->source->people([]));
    }

    private function draft(string $id, string $status, string $branch, string $meta, string $assignee, string $due, string $archived): void
    {
        $this->pdo->exec("INSERT INTO [rfq].[rfq_draft_quotes] ([id], [created_at], [source], [status], [quote_details], [email_meta], [branch], [assignee_email], [due_at], [archived_at])
            VALUES ('" . str_pad($id, 32) . "', N'2026-10-01T00:00:00+00:00', N'web', {$status}, N'{}', {$meta}, {$branch}, {$assignee}, {$due}, {$archived})");
    }

    private function session(string $id, string $draft, int $legacy): void
    {
        $this->pdo->exec("INSERT INTO [rfq].[rfq_sessions] ([id], [created_at], [line_count], [draft_id], [legacy_unlinked])
            VALUES ('" . str_pad($id, 32) . "', N'2026-10-01T00:00:00+00:00', 1, " . ($draft === '' ? 'NULL' : "'" . str_pad($draft, 32) . "'") . ", {$legacy})");
    }
}
