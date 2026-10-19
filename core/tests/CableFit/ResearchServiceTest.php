<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Core\CableFit\Application\CableFitCandidateService;
use TriCab\Core\CableFit\Application\ResearchRunner;
use TriCab\Core\CableFit\Application\ResearchService;
use TriCab\Core\CableFit\Contract\CableFitResearchWake;
use TriCab\Core\CableFit\Domain\CandidateNotFound;
use TriCab\Core\CableFit\Domain\ResearchInputInvalid;
use TriCab\Core\CableFit\Domain\ResearchNoSources;
use TriCab\Core\CableFit\Domain\ResearchOff;
use TriCab\Core\CableFit\Domain\ResearchRunNotFound;
use TriCab\Core\CableFit\Domain\ResearchSourceExists;
use TriCab\Core\CableFit\Domain\ResearchSourceNotFound;
use TriCab\Core\CableFit\Infrastructure\InMemoryCableFitCandidateRepository;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchRunRepository;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchSourceRepository;
use TriCab\Core\CableFit\Infrastructure\NullCableFitResearchWake;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

final class ResearchServiceTest extends TestCase
{
    private InMemoryResearchRunRepository $runs;
    private InMemoryResearchSourceRepository $sources;
    private NullCableFitResearchWake $wake;
    private CableFitCandidateService $candidates;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->runs = new InMemoryResearchRunRepository();
        $this->sources = new InMemoryResearchSourceRepository();
        $this->wake = new NullCableFitResearchWake();
        $this->now = new DateTimeImmutable('2026-10-19T10:00:00Z');
        $this->seq = 0;
        $this->candidates = new CableFitCandidateService(
            new InMemoryCableFitCandidateRepository(),
            new FakeNprRequestReader(),
            clock: fn (): DateTimeImmutable => $this->now,
            idGenerator: function (): string {
                return 'cand-' . ++$this->seq;
            },
        );
    }

    private function service(bool $enabled = true, ?CableFitResearchWake $wake = null): ResearchService
    {
        return new ResearchService(
            $this->candidates,
            $this->sources,
            $this->runs,
            $wake ?? $this->wake,
            $enabled,
            fn (): DateTimeImmutable => $this->now,
            function (): string {
                return 'id-' . ++$this->seq;
            },
        );
    }

    private function user(): AuthenticatedUser
    {
        return new AuthenticatedUser(7, 'user-7', 'tsd@example.test', 'TSD User', 'active', ['TSD'], ['TSD.CABLEFIT.RESEARCH']);
    }

    private function candidate(): string
    {
        $id = $this->candidates->create($this->user(), null, null, 'Design');
        $this->candidates->save($this->user(), $id, 1, ['conductor' => ['material' => 'copper'], 'insulation' => ['material' => 'XLPE'], '_isPartial' => true]);

        return $id;
    }

    private function enableSource(string $domain = 'iec.ch'): string
    {
        return $this->service()->createSource($this->user(), $domain, 'IEC', 'standards_body');
    }

    public function test_a_run_with_no_parameters_uses_the_latest_construction(): void
    {
        $this->enableSource();
        $candidate = $this->candidate();

        $runId = $this->service()->requestRun($this->user(), $candidate, '  underground  ');

        $run = $this->runs->find($runId);
        self::assertNotNull($run);
        self::assertSame('queued', $run->status);
        self::assertSame($candidate, $run->candidatePublicId);
        self::assertSame('underground', $run->application);
        self::assertSame(['conductor' => ['material' => 'copper'], 'insulation' => ['material' => 'XLPE'], '_isPartial' => true], $run->parameters);
        self::assertSame(['iec.ch'], $run->sourcesUsed);
        self::assertSame(7, $run->requestedByUserId);
        self::assertSame('TSD User', $run->requestedByName);
        self::assertEquals($this->now, $run->requestedAt);
        self::assertSame(1, $this->wake->signals);
    }

    public function test_given_parameters_win_over_the_construction(): void
    {
        $this->enableSource();
        $candidate = $this->candidate();

        $runId = $this->service()->requestRun($this->user(), $candidate, 'app', ['conductor' => ['material' => 'aluminium']]);

        self::assertSame(['conductor' => ['material' => 'aluminium']], $this->runs->find($runId)?->parameters);
    }

    public function test_a_blank_design_needs_parameters(): void
    {
        $this->enableSource();
        $blank = $this->candidates->create($this->user(), null, null, 'Blank');

        $this->expectException(ResearchInputInvalid::class);
        $this->expectExceptionMessage('no parameters set');

        $this->service()->requestRun($this->user(), $blank, 'app');
    }

    public function test_requests_are_validated(): void
    {
        $this->enableSource();
        $candidate = $this->candidate();

        foreach ([null, '', '   ', 5, str_repeat('x', 2001)] as $application) {
            try {
                $this->service()->requestRun($this->user(), $candidate, $application);
                self::fail('Expected ResearchInputInvalid');
            } catch (ResearchInputInvalid) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertNotSame('', $this->service()->requestRun($this->user(), $candidate, str_repeat('x', 2000)));

        $this->expectException(ResearchInputInvalid::class);
        $this->service()->requestRun($this->user(), $candidate, 'app', ['a', 'b']);
    }

    public function test_research_off_refuses_before_anything_else(): void
    {
        $this->enableSource();

        $this->expectException(ResearchOff::class);

        try {
            $this->service(false)->requestRun($this->user(), 'missing', 'app');
        } finally {
            self::assertSame(0, $this->wake->signals);
        }
    }

    public function test_no_enabled_source_refuses(): void
    {
        $candidate = $this->candidate();
        $id = $this->enableSource();
        $this->service()->updateSource($this->user(), $id, 'IEC', 'standards_body', false);

        $this->expectException(ResearchNoSources::class);

        $this->service()->requestRun($this->user(), $candidate, 'app');
    }

    public function test_an_unknown_candidate_is_not_found(): void
    {
        $this->enableSource();

        $this->expectException(CandidateNotFound::class);

        $this->service()->requestRun($this->user(), 'nope', 'app');
    }

    public function test_a_failing_wake_does_not_fail_the_request(): void
    {
        $this->enableSource();
        $candidate = $this->candidate();
        $broken = new class () implements CableFitResearchWake {
            public function signal(): void
            {
                throw new RuntimeException('disk full');
            }
        };

        $runId = $this->service(true, $broken)->requestRun($this->user(), $candidate, 'app');

        self::assertSame('queued', $this->runs->find($runId)?->status);
    }

    public function test_source_domains_are_validated_and_lowercased(): void
    {
        $service = $this->service();
        $id = $service->createSource($this->user(), '  WWW.Example.COM ', 'Example', 'other', '  note  ');

        $source = $this->sources->findByPublicId($id);
        self::assertNotNull($source);
        self::assertSame('www.example.com', $source->domain);
        self::assertSame('note', $source->notes);
        self::assertTrue($source->enabled);

        foreach (['', 'https://iec.ch', 'iec.ch/path', 'iec', 'iec.ch:443', 'has space.com', '-bad.com', 'a..b.com', 'x.c', null, 5] as $bad) {
            try {
                $service->createSource($this->user(), $bad, 'Name', 'other');
                self::fail('Expected ResearchInputInvalid for ' . json_encode($bad));
            } catch (ResearchInputInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_source_fields_are_validated(): void
    {
        $service = $this->service();

        foreach ([['', 'other', null], [str_repeat('n', 201), 'other', null], ['Name', 'blog', null], ['Name', null, null], ['Name', 'other', 5], ['Name', 'other', str_repeat('n', 1001)]] as [$name, $category, $notes]) {
            try {
                $service->createSource($this->user(), 'valid.example.com', $name, $category, $notes);
                self::fail('Expected ResearchInputInvalid');
            } catch (ResearchInputInvalid) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->sources->all());
    }

    public function test_a_duplicate_domain_is_refused_whatever_its_case(): void
    {
        $service = $this->service();
        $service->createSource($this->user(), 'iec.ch', 'IEC', 'standards_body');

        $this->expectException(ResearchSourceExists::class);

        $service->createSource($this->user(), 'IEC.CH', 'Again', 'other');
    }

    public function test_a_source_can_be_edited_but_not_its_domain(): void
    {
        $service = $this->service();
        $id = $service->createSource($this->user(), 'iec.ch', 'IEC', 'standards_body');

        $service->updateSource($this->user(), $id, 'IEC Geneva', 'other', false, 'on hold');

        $source = $this->sources->findByPublicId($id);
        self::assertNotNull($source);
        self::assertSame(['iec.ch', 'IEC Geneva', 'other', false, 'on hold'], [$source->domain, $source->name, $source->category, $source->enabled, $source->notes]);
        self::assertSame([], $this->sources->enabledDomains());

        $this->expectException(ResearchInputInvalid::class);
        $service->updateSource($this->user(), $id, 'IEC', 'other', 'yes');
    }

    public function test_updating_an_unknown_source_is_not_found(): void
    {
        $this->expectException(ResearchSourceNotFound::class);

        $this->service()->updateSource($this->user(), 'nope', 'Name', 'other', true);
    }

    public function test_runs_are_listed_newest_first_and_detail_belongs_to_its_candidate(): void
    {
        $this->enableSource();
        $one = $this->candidate();
        $other = $this->candidate();
        $service = $this->service();
        $first = $service->requestRun($this->user(), $one, 'first');
        $this->now = $this->now->modify('+1 hour');
        $second = $service->requestRun($this->user(), $one, 'second');
        $service->requestRun($this->user(), $other, 'elsewhere');

        self::assertSame([$second, $first], array_map(static fn ($r) => $r->publicId, $service->listRuns($this->user(), $one)));
        self::assertSame('first', $service->getRun($this->user(), $one, $first)->run->application);

        $this->expectException(ResearchRunNotFound::class);
        $service->getRun($this->user(), $other, $first);
    }

    public function test_finding_order_is_recent_first_then_confidence(): void
    {
        $this->enableSource();
        $candidate = $this->candidate();
        $service = $this->service();
        $runId = $service->requestRun($this->user(), $candidate, 'app');

        $model = new FakeResearchModel(FakeResearchModel::answer(FakeResearchModel::json([
            FakeResearchModel::finding(['claim' => 'old high', 'publishedOn' => '2019-01-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'high']),
            FakeResearchModel::finding(['claim' => 'undated high', 'confidence' => 'high']),
            FakeResearchModel::finding(['claim' => 'recent low', 'publishedOn' => '2026-05-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'low']),
            FakeResearchModel::finding(['claim' => 'recent high', 'publishedOn' => '2026-01-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'high']),
            FakeResearchModel::finding(['claim' => 'recent medium b', 'publishedOn' => '2025-12-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'medium']),
            FakeResearchModel::finding(['claim' => 'recent medium a', 'publishedOn' => '2025-11-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'medium']),
        ])));
        (new ResearchRunner($this->runs, $this->sources, $model, 8, fn (): DateTimeImmutable => $this->now))->runNext();

        $detail = $service->getRun($this->user(), $candidate, $runId);

        self::assertSame(
            ['recent high', 'recent medium b', 'recent medium a', 'recent low', 'old high', 'undated high'],
            array_map(static fn ($f) => $f->claim, $detail->findings),
        );
        self::assertSame([true, true, true, true, false, false], array_map(fn ($f) => $detail->isRecent($f), $detail->findings));
    }
}
