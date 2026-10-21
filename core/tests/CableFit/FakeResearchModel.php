<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use Closure;
use LogicException;
use Throwable;
use TriCab\Core\CableFit\Contract\ResearchModel;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

/** A scripted research model: no network. Each call takes the next queued response, or throws a queued failure. */
final class FakeResearchModel implements ResearchModel
{
    /** @var list<array{system: string, user: string, domains: list<string>, maxSearches: int}> */
    public array $calls = [];

    /** @var (Closure(): void)|null runs inside each call, before the answer is returned */
    public ?Closure $onCall = null;

    /** @var list<ResearchModelResponse|Throwable> */
    private array $queue;

    public function __construct(ResearchModelResponse|Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function research(string $system, string $user, array $allowedDomains, int $maxSearches): ResearchModelResponse
    {
        $this->calls[] = ['system' => $system, 'user' => $user, 'domains' => $allowedDomains, 'maxSearches' => $maxSearches];
        if ($this->onCall !== null) {
            ($this->onCall)();
        }
        $next = array_shift($this->queue) ?? throw new LogicException('FakeResearchModel has no more responses.');
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * A response in the shape the web search tool produces: a search, its results, then the answer text.
     *
     * @param array<string, string|null> $pageAges search result URL => page_age (null: the result has none)
     */
    public static function answer(string $text, array $pageAges = [], ?ResearchCost $cost = null): ResearchModelResponse
    {
        $results = [];
        foreach ($pageAges as $url => $age) {
            $results[] = ['type' => 'web_search_result', 'url' => $url, 'title' => 'Result', 'page_age' => $age, 'encrypted_content' => 'x'];
        }

        return new ResearchModelResponse(
            [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'xlpe']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => $results],
                ['type' => 'text', 'text' => $text],
            ],
            $cost ?? new ResearchCost('claude-sonnet-5', 1000, 200, 1),
        );
    }

    /**
     * One finding as the model writes it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function finding(array $overrides = []): array
    {
        return $overrides + [
            'parameter' => 'insulation.material',
            'claim' => 'XLPE is rated for 90 C continuous conductor temperature.',
            'relevance' => 'Sets the continuous rating in a hot climate.',
            'url' => 'https://webstore.iec.ch/publication/1',
            'title' => 'IEC 60502-2',
            'publisher' => 'IEC',
            'publishedOn' => null,
            'dateBasis' => 'undated',
            'confidence' => 'high',
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, mixed>|null  $assessment
     */
    public static function json(array $findings, ?array $assessment = null): string
    {
        return json_encode($assessment === null ? ['findings' => $findings] : ['findings' => $findings, 'assessment' => $assessment], JSON_THROW_ON_ERROR);
    }
}
