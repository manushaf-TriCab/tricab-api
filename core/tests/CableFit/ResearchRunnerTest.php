<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Core\CableFit\Application\ResearchRunner;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchRunRepository;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchSourceRepository;

/** The worker's unit of work: claim one run, research it, record the outcome. */
final class ResearchRunnerTest extends TestCase
{
    private const CANDIDATE = 'cand-1';

    private InMemoryResearchRunRepository $runs;
    private InMemoryResearchSourceRepository $sources;
    private int $seq = 0;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->runs = new InMemoryResearchRunRepository();
        $this->sources = new InMemoryResearchSourceRepository();
        $this->seq = 0;
        $this->now = new DateTimeImmutable('2026-10-19T10:00:00Z');
        $this->source('iec.ch', true);
        $this->source('prysmian.com', true);
        $this->source('nexans.com', false);
    }

    private function source(string $domain, bool $enabled): void
    {
        $this->sources->add(new ResearchSource('src-' . $domain, $domain, $domain, 'other', $enabled, null, $this->now, $this->now), 1);
    }

    private function queue(string $id = 'run-1', ?DateTimeImmutable $at = null): void
    {
        $this->runs->add(ResearchRun::queued(
            $id,
            self::CANDIDATE,
            'underground distribution in a hot climate',
            ['conductor' => ['material' => 'copper'], 'insulation' => ['material' => 'XLPE'], 'systemVoltage_kV' => 11],
            ['iec.ch', 'prysmian.com', 'nexans.com'],
            7,
            'TSD User',
            $at ?? $this->now->modify('-5 minutes'),
        ));
    }

    private function runner(FakeResearchModel $model, int $maxSearches = 8): ResearchRunner
    {
        return new ResearchRunner(
            $this->runs,
            $this->sources,
            $model,
            $maxSearches,
            fn (): DateTimeImmutable => $this->now,
            function (): string {
                return 'finding-' . ++$this->seq;
            },
        );
    }

    private function stored(string $id = 'run-1'): ResearchRun
    {
        $run = $this->runs->find($id);
        self::assertNotNull($run);

        return $run;
    }

    public function test_nothing_queued_means_no_model_call(): void
    {
        $model = new FakeResearchModel();

        self::assertNull($this->runner($model)->runNext());
        self::assertSame([], $model->calls);
    }

    public function test_a_queued_run_goes_running_then_succeeded_with_findings_and_cost(): void
    {
        $this->queue();
        self::assertSame('queued', $this->stored()->status);

        $model = new FakeResearchModel(FakeResearchModel::answer(
            FakeResearchModel::json([
                FakeResearchModel::finding(['url' => 'https://webstore.iec.ch/p/1', 'confidence' => 'medium']),
                FakeResearchModel::finding(['url' => 'https://www.prysmian.com/xlpe', 'parameter' => 'insulation.material']),
            ]),
            ['https://www.prysmian.com/xlpe' => 'September 3, 2026'],
            new ResearchCost('claude-sonnet-5', 4200, 900, 3),
        ));
        $statusDuringCall = null;
        $model->onCall = function () use (&$statusDuringCall): void {
            $statusDuringCall = $this->stored()->status;
        };

        $done = $this->runner($model, 5)->runNext();

        self::assertSame('running', $statusDuringCall);
        self::assertNotNull($done);
        self::assertSame('succeeded', $done->status);
        self::assertNull($done->error);
        self::assertNotNull($done->startedAt);
        self::assertNotNull($done->finishedAt);
        self::assertSame(2, $done->findingCount);
        self::assertSame(0, $done->droppedFindings);
        self::assertSame('claude-sonnet-5', $done->model);
        self::assertSame([4200, 900, 3], [$done->inputTokens, $done->outputTokens, $done->searchRequests]);
        // Only the enabled sources are searched and recorded; the disabled nexans.com is not.
        self::assertSame(['iec.ch', 'prysmian.com'], $done->sourcesUsed);
        self::assertSame(['iec.ch', 'prysmian.com'], $model->calls[0]['domains']);
        self::assertSame(5, $model->calls[0]['maxSearches']);
        self::assertStringContainsString('- conductor.material: copper', $model->calls[0]['user']);
        self::assertStringContainsString('underground distribution in a hot climate', $model->calls[0]['user']);

        $findings = $this->runs->findings('run-1');
        self::assertSame(['https://webstore.iec.ch/p/1', 'https://www.prysmian.com/xlpe'], array_map(static fn ($f) => $f->url, $findings));
        self::assertSame('2026-09-03', $findings[1]->publishedOn);
        self::assertSame('page_age', $findings[1]->dateBasis);
        self::assertSame([0, 1], array_map(static fn ($f) => $f->sortOrder, $findings));
    }

    public function test_findings_off_the_source_list_are_dropped_and_counted(): void
    {
        $this->queue();
        $model = new FakeResearchModel(FakeResearchModel::answer(FakeResearchModel::json([
            FakeResearchModel::finding(['url' => 'https://iec.ch/ok', 'claim' => 'kept 1']),
            FakeResearchModel::finding(['url' => 'https://iec.ch.evil.com/x', 'claim' => 'lookalike']),
            FakeResearchModel::finding(['url' => 'https://www.nexans.com/disabled', 'claim' => 'disabled source']),
            FakeResearchModel::finding(['url' => 'https://random-blog.example/cables', 'claim' => 'not a source']),
            FakeResearchModel::finding(['url' => 'https://webstore.iec.ch/sub', 'claim' => 'kept 2']),
        ])));

        $done = $this->runner($model)->runNext();

        self::assertNotNull($done);
        self::assertSame('succeeded', $done->status);
        self::assertSame(3, $done->droppedFindings);
        self::assertSame(2, $done->findingCount);
        self::assertSame(['kept 1', 'kept 2'], array_map(static fn ($f) => $f->claim, $this->runs->findings('run-1')));
        // Kept findings are renumbered without gaps.
        self::assertSame([0, 1], array_map(static fn ($f) => $f->sortOrder, $this->runs->findings('run-1')));
    }

    public function test_a_source_disabled_after_the_request_is_not_trusted(): void
    {
        $this->queue();
        $this->sources->save(new ResearchSource('src-prysmian.com', 'prysmian.com', 'p', 'other', false, null, $this->now, $this->now), 1);
        $model = new FakeResearchModel(FakeResearchModel::answer(FakeResearchModel::json([
            FakeResearchModel::finding(['url' => 'https://prysmian.com/x']),
        ])));

        $done = $this->runner($model)->runNext();

        self::assertNotNull($done);
        self::assertSame(1, $done->droppedFindings);
        self::assertSame(0, $done->findingCount);
        self::assertSame(['iec.ch'], $model->calls[0]['domains']);
    }

    public function test_a_model_error_fails_the_run_with_the_message_and_no_findings(): void
    {
        $this->queue();

        $done = $this->runner(new FakeResearchModel(new ResearchModelFailed('The model declined the request.')))->runNext();

        self::assertNotNull($done);
        self::assertSame('failed', $done->status);
        self::assertSame('The model declined the request.', $done->error);
        self::assertNotNull($done->finishedAt);
        self::assertSame(0, $done->findingCount);
        self::assertNull($done->inputTokens);
    }

    public function test_unparseable_output_fails_the_run_but_keeps_the_cost(): void
    {
        $this->queue();
        $model = new FakeResearchModel(FakeResearchModel::answer('Sorry, no JSON today.', [], new ResearchCost('claude-sonnet-5', 3000, 120, 2)));

        $done = $this->runner($model)->runNext();

        self::assertNotNull($done);
        self::assertSame('failed', $done->status);
        self::assertSame('The model answered with something that is not valid JSON.', $done->error);
        self::assertSame([3000, 120, 2], [$done->inputTokens, $done->outputTokens, $done->searchRequests]);
        self::assertSame([], $this->runs->findings('run-1'));
    }

    public function test_an_unexpected_error_fails_the_run_without_leaking_its_message(): void
    {
        $this->queue();

        $done = $this->runner(new FakeResearchModel(new RuntimeException('secret connection string')))->runNext();

        self::assertNotNull($done);
        self::assertSame('failed', $done->status);
        self::assertSame('Unexpected error (RuntimeException).', $done->error);
        self::assertStringNotContainsString('secret', (string) $done->error);
    }

    public function test_no_enabled_source_at_run_time_fails_the_run_without_calling_the_model(): void
    {
        $this->queue();
        $this->sources->save(new ResearchSource('src-iec.ch', 'iec.ch', 'x', 'other', false, null, $this->now, $this->now), 1);
        $this->sources->save(new ResearchSource('src-prysmian.com', 'prysmian.com', 'x', 'other', false, null, $this->now, $this->now), 1);
        $model = new FakeResearchModel();

        $done = $this->runner($model)->runNext();

        self::assertNotNull($done);
        self::assertSame('failed', $done->status);
        self::assertSame('No research source is enabled.', $done->error);
        self::assertSame([], $model->calls);
    }

    public function test_runs_are_taken_oldest_first_one_at_a_time(): void
    {
        $this->queue('late', $this->now->modify('-1 minute'));
        $this->queue('early', $this->now->modify('-10 minutes'));
        $model = new FakeResearchModel(
            FakeResearchModel::answer(FakeResearchModel::json([])),
            FakeResearchModel::answer(FakeResearchModel::json([])),
        );
        $runner = $this->runner($model);

        $first = $runner->runNext();
        self::assertSame('early', $first?->publicId);
        self::assertSame('queued', $this->stored('late')->status);
        self::assertSame(1, $this->runs->outstanding());

        self::assertSame('late', $runner->runNext()?->publicId);
        self::assertSame(0, $this->runs->outstanding());
        self::assertNull($runner->runNext());
        self::assertCount(2, $model->calls);
    }

    public function test_a_run_left_running_by_a_crashed_worker_is_taken_again_after_30_minutes(): void
    {
        $this->queue();
        $crashed = $this->runs->claimNext($this->now->modify('-40 minutes'), $this->now->modify('-70 minutes'));
        self::assertSame('running', $crashed?->status);
        $model = new FakeResearchModel(FakeResearchModel::answer(FakeResearchModel::json([])));

        $done = $this->runner($model)->runNext();

        self::assertSame('succeeded', $done?->status);
    }

    public function test_a_recent_claim_is_left_alone(): void
    {
        $this->queue();
        $this->runs->claimNext($this->now->modify('-5 minutes'), $this->now->modify('-70 minutes'));
        $model = new FakeResearchModel();

        self::assertNull($this->runner($model)->runNext());
        self::assertSame([], $model->calls);
    }
}
