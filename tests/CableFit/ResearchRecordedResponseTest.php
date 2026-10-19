<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\ResearchRunner;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchSource;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchRunRepository;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchSourceRepository;
use TriCab\Core\Tests\CableFit\FakeResearchModel;

/**
 * The runner on a real response: one live call through FoundryResearchModel (19 Oct 2026, 11 kV XLPE 3-core
 * copper, "underground distribution in a hot climate", max_uses 3), recorded as
 * tests/Fixtures/CableFit/research-response.json. Replayed here through a fake model, so no network.
 */
final class ResearchRecordedResponseTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        $path = dirname(__DIR__) . '/Fixtures/CableFit/research-response.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, $path);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }

    /**
     * @param list<string> $enabled
     *
     * @return array{0: ResearchRun, 1: InMemoryResearchRunRepository, 2: FakeResearchModel}
     */
    private function replay(array $enabled, string $requestedAt = '2026-10-19T10:00:00Z'): array
    {
        $fixture = self::fixture();
        $recording = $fixture['_recording'];
        self::assertIsArray($recording);
        $at = new DateTimeImmutable($requestedAt);
        $sources = new InMemoryResearchSourceRepository();
        foreach (is_array($recording['allowedDomains']) ? $recording['allowedDomains'] : [] as $domain) {
            self::assertIsString($domain);
            $sources->add(new ResearchSource('s-' . $domain, $domain, $domain, 'other', in_array($domain, $enabled, true), null, $at, $at), 1);
        }
        $runs = new InMemoryResearchRunRepository();
        $application = $recording['application'];
        self::assertIsString($application);
        $parameters = $recording['parameters'];
        self::assertIsArray($parameters);
        /** @var array<string, mixed> $parameters */
        $runs->add(ResearchRun::queued('run-1', 'cand-1', $application, $parameters, $enabled, 7, 'TSD User', $at));
        $model = new FakeResearchModel(ResearchModelResponse::fromMessage($fixture));
        $done = (new ResearchRunner($runs, $sources, $model, 3, static fn (): DateTimeImmutable => $at))->runNext();
        self::assertNotNull($done);

        return [$done, $runs, $model];
    }

    public function test_the_recording_is_a_clean_messages_response(): void
    {
        $fixture = self::fixture();

        self::assertSame(['_recording', 'id', 'type', 'role', 'model', 'content', 'stop_reason', 'usage'], array_keys($fixture));
        self::assertSame('end_turn', $fixture['stop_reason']);
        self::assertSame('claude-sonnet-5', $fixture['model']);
        $raw = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/CableFit/research-response.json');
        foreach (['api-key', 'x-api', 'authorization', 'bearer', 'FOUNDRY_', 'sk-'] as $secretish) {
            self::assertStringNotContainsStringIgnoringCase($secretish, $raw, $secretish);
        }
        $types = array_unique(array_map(static fn (mixed $b): string => is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : '', (array) $fixture['content']));
        sort($types);
        self::assertSame(['server_tool_use', 'text', 'thinking', 'web_search_tool_result'], $types);
    }

    public function test_the_runner_turns_the_recorded_answer_into_findings_and_cost(): void
    {
        $all = (array) self::fixture()['_recording'];
        /** @var list<string> $domains */
        $domains = $all['allowedDomains'];

        [$run, $runs, $model] = $this->replay($domains);

        self::assertSame('succeeded', $run->status);
        self::assertSame(10, $run->findingCount);
        self::assertSame(0, $run->droppedFindings);
        self::assertSame('claude-sonnet-5', $run->model);
        self::assertSame([69846, 3230, 3], [$run->inputTokens, $run->outputTokens, $run->searchRequests]);
        self::assertSame(3, $model->calls[0]['maxSearches']);
        self::assertCount(16, $model->calls[0]['domains']);
        self::assertStringContainsString('- conductor.material: copper', $model->calls[0]['user']);
        self::assertStringContainsString('- layup.coreCount: 3', $model->calls[0]['user']);
        self::assertStringContainsString('underground distribution in a hot climate', $model->calls[0]['user']);

        $findings = $runs->findings('run-1');
        $bases = array_count_values(array_map(static fn ($f) => $f->dateBasis, $findings));
        ksort($bases);
        // Search results carried a page_age for six of the findings; the model read dates off two more pages.
        self::assertSame(['page_age' => 6, 'stated_on_page' => 2, 'undated' => 2], $bases);
        foreach ($findings as $finding) {
            if ($finding->dateBasis === 'page_age') {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $finding->publishedOn, $finding->url);
            }
            self::assertContains($finding->confidence, ['high', 'medium', 'low']);
            self::assertNotSame('', $finding->claim);
        }
        $stated = array_values(array_filter($findings, static fn ($f) => $f->dateBasis === 'stated_on_page'));
        self::assertSame(['2022-10', '2021'], array_map(static fn ($f) => $f->publishedOn, $stated));
    }

    public function test_with_fewer_sources_enabled_the_rest_are_dropped_and_counted(): void
    {
        [$run, $runs] = $this->replay(['ieee.org', 'cigre.org', 'iec.ch']);

        self::assertSame('succeeded', $run->status);
        self::assertSame(3, $run->findingCount);
        self::assertSame(7, $run->droppedFindings);
        foreach ($runs->findings('run-1') as $finding) {
            self::assertMatchesRegularExpression('~^https://(ieeexplore\.ieee\.org|electra\.cigre\.org|webstore\.iec\.ch)/~', $finding->url);
        }
    }

    public function test_the_recent_window_is_counted_from_when_the_run_was_requested(): void
    {
        $all = (array) self::fixture()['_recording'];
        /** @var list<string> $domains */
        $domains = $all['allowedDomains'];
        [$run, $runs] = $this->replay($domains, '2025-06-01T09:00:00Z');

        $recent = array_values(array_filter($runs->findings('run-1'), static fn ($f) => $f->withinRecentWindow($run->requestedAt)));

        // Only the 29 Oct 2023 page is inside the 24 months before 1 June 2025 (it is cited five times).
        self::assertCount(5, $recent);
        foreach ($recent as $finding) {
            self::assertSame('2023-10-29', $finding->publishedOn);
        }
    }
}
