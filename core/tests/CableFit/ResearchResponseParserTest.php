<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableFit\Application\ResearchResponseParser;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

final class ResearchResponseParserTest extends TestCase
{
    private int $seq = 0;

    private function parser(): ResearchResponseParser
    {
        $this->seq = 0;

        return new ResearchResponseParser(function (): string {
            return 'f-' . ++$this->seq;
        });
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, string|null> $pageAges
     *
     * @return list<ResearchFinding>
     */
    private function parseFindings(array $findings, array $pageAges = []): array
    {
        return $this->parser()->parse(FakeResearchModel::answer(FakeResearchModel::json($findings), $pageAges));
    }

    public function test_plain_json_is_parsed_into_findings(): void
    {
        $findings = $this->parseFindings([FakeResearchModel::finding(), FakeResearchModel::finding(['parameter' => 'conductor.material', 'confidence' => 'MEDIUM'])]);

        self::assertCount(2, $findings);
        self::assertSame('f-1', $findings[0]->publicId);
        self::assertSame('insulation.material', $findings[0]->parameter);
        self::assertSame('https://webstore.iec.ch/publication/1', $findings[0]->url);
        self::assertSame('IEC', $findings[0]->publisher);
        self::assertSame('high', $findings[0]->confidence);
        self::assertSame('medium', $findings[1]->confidence);
        self::assertSame([0, 1], [$findings[0]->sortOrder, $findings[1]->sortOrder]);
    }

    public function test_code_fences_and_lead_in_text_are_tolerated(): void
    {
        $json = FakeResearchModel::json([FakeResearchModel::finding()]);

        foreach ([
            "```json\n" . $json . "\n```",
            "```\n" . $json . "\n```",
            "Here is what I found:\n\n```json\n" . $json . "\n```\nHope that helps.",
            'Based on my search, ' . $json . ' That is all.',
        ] as $text) {
            $findings = $this->parser()->parse(FakeResearchModel::answer($text));
            self::assertCount(1, $findings, $text);
        }
    }

    public function test_the_answer_may_be_split_over_several_text_blocks(): void
    {
        $json = FakeResearchModel::json([FakeResearchModel::finding()]);
        $half = intdiv(strlen($json), 2);
        $response = new ResearchModelResponse(
            [
                ['type' => 'text', 'text' => 'Searching now.'],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 's', 'content' => []],
                ['type' => 'text', 'text' => substr($json, 0, $half)],
                ['type' => 'text', 'text' => substr($json, $half)],
            ],
            new ResearchCost('m', 1, 1, 1),
        );

        self::assertCount(1, $this->parser()->parse($response));
    }

    public function test_an_empty_findings_list_is_a_valid_answer(): void
    {
        self::assertSame([], $this->parseFindings([]));
    }

    public function test_a_bare_list_of_findings_is_accepted(): void
    {
        $text = json_encode([FakeResearchModel::finding()], JSON_THROW_ON_ERROR);

        self::assertCount(1, $this->parser()->parse(FakeResearchModel::answer($text)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'prose only' => ['I could not find anything useful, sorry.'],
            'truncated json' => ['{"findings":[{"parameter":"a","claim":"b"'],
            'empty text' => ['   '],
            'json without findings' => ['{"results":[]}'],
            'findings not a list' => ['{"findings":{"a":1}}'],
            'findings none usable' => ['{"findings":[{"parameter":"a"},{"claim":"c"},"text"]}'],
        ];
    }

    #[DataProvider('invalidAnswers')]
    public function test_an_answer_that_is_not_the_expected_json_fails_cleanly(string $text): void
    {
        $this->expectException(ResearchModelFailed::class);

        $this->parser()->parse(FakeResearchModel::answer($text));
    }

    public function test_a_response_with_no_text_fails_cleanly(): void
    {
        $this->expectException(ResearchModelFailed::class);
        $this->expectExceptionMessage('no text answer');

        $this->parser()->parse(new ResearchModelResponse([['type' => 'server_tool_use', 'id' => 'x', 'name' => 'web_search', 'input' => []]], new ResearchCost('m', 0, 0, 0)));
    }

    public function test_page_age_beats_the_date_the_model_states(): void
    {
        $findings = $this->parseFindings(
            [FakeResearchModel::finding(['url' => 'https://www.iec.ch/doc', 'publishedOn' => '2019', 'dateBasis' => 'stated_on_page'])],
            ['https://www.iec.ch/doc' => 'March 11, 2026'],
        );

        self::assertSame('2026-03-11', $findings[0]->publishedOn);
        self::assertSame('page_age', $findings[0]->dateBasis);
    }

    public function test_page_age_is_matched_by_url_ignoring_www_fragment_and_trailing_slash(): void
    {
        $findings = $this->parseFindings(
            [FakeResearchModel::finding(['url' => 'https://iec.ch/doc/#section'])],
            ['https://www.iec.ch/doc' => 'April 2, 2025'],
        );

        self::assertSame('2025-04-02', $findings[0]->publishedOn);
        self::assertSame('page_age', $findings[0]->dateBasis);
    }

    public function test_a_page_age_for_another_url_is_not_used(): void
    {
        $findings = $this->parseFindings(
            [FakeResearchModel::finding(['url' => 'https://iec.ch/b', 'publishedOn' => '2024-06', 'dateBasis' => 'stated_on_page'])],
            ['https://iec.ch/a' => 'March 11, 2026'],
        );

        self::assertSame('2024-06', $findings[0]->publishedOn);
        self::assertSame('stated_on_page', $findings[0]->dateBasis);
    }

    public function test_the_model_date_is_used_when_the_result_has_no_usable_page_age(): void
    {
        foreach ([null, '2 days ago', 'not a date'] as $age) {
            $findings = $this->parseFindings(
                [FakeResearchModel::finding(['url' => 'https://iec.ch/a', 'publishedOn' => 'January 5, 2025', 'dateBasis' => 'stated_on_page'])],
                ['https://iec.ch/a' => $age],
            );

            self::assertSame('2025-01-05', $findings[0]->publishedOn, (string) $age);
            self::assertSame('stated_on_page', $findings[0]->dateBasis);
        }
    }

    public function test_a_finding_with_no_date_anywhere_is_undated(): void
    {
        foreach ([
            ['publishedOn' => null, 'dateBasis' => 'undated'],
            ['publishedOn' => '', 'dateBasis' => 'stated_on_page'],
            ['publishedOn' => 'sometime last year', 'dateBasis' => 'stated_on_page'],
            ['publishedOn' => '2025-02-31', 'dateBasis' => 'stated_on_page'],
            ['publishedOn' => '2025-03-01', 'dateBasis' => 'undated'],
        ] as $dates) {
            $findings = $this->parseFindings([FakeResearchModel::finding($dates)]);

            self::assertNull($findings[0]->publishedOn, json_encode($dates) ?: '');
            self::assertSame('undated', $findings[0]->dateBasis);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function dates(): array
    {
        return [
            'long month' => ['March 11, 2026', '2026-03-11'],
            'short month' => ['Mar 11, 2026', '2026-03-11'],
            'day first' => ['11 March 2026', '2026-03-11'],
            'month and year' => ['March 2026', '2026-03'],
            'iso day' => ['2026-03-11', '2026-03-11'],
            'iso datetime' => ['2026-03-11T08:30:00Z', '2026-03-11'],
            'iso month' => ['2026-03', '2026-03'],
            'year' => ['2026', '2026'],
            'padded' => ['  2026-03-11 ', '2026-03-11'],
            'relative' => ['3 weeks ago', null],
            'impossible day' => ['2026-02-30', null],
            'bad month' => ['2026-13', null],
            'odd year' => ['1066', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates_are_normalised(string $input, ?string $expected): void
    {
        self::assertSame($expected, ResearchResponseParser::normaliseDate($input));
    }

    public function test_unknown_confidence_becomes_low_and_missing_parameter_becomes_general(): void
    {
        $findings = $this->parseFindings([FakeResearchModel::finding(['confidence' => 'certain', 'parameter' => ''])]);

        self::assertSame('low', $findings[0]->confidence);
        self::assertSame('general', $findings[0]->parameter);
    }

    public function test_long_fields_are_cut_to_the_column_sizes(): void
    {
        $findings = $this->parseFindings([FakeResearchModel::finding([
            'claim' => str_repeat('c', 5000),
            'title' => str_repeat('t', 900),
            'publisher' => str_repeat('p', 400),
        ])]);

        self::assertSame(2000, mb_strlen($findings[0]->claim));
        self::assertSame(500, mb_strlen($findings[0]->title));
        self::assertSame(200, mb_strlen((string) $findings[0]->publisher));
    }

    public function test_parse_answer_keeps_the_models_index_the_stance_and_the_raw_assessment(): void
    {
        $parser = new \TriCab\Core\CableFit\Application\ResearchResponseParser(static fn (): string => 'id');
        $answer = $parser->parseAnswer(FakeResearchModel::answer(FakeResearchModel::json(
            [
                FakeResearchModel::finding(['stance' => 'supports']),
                ['claim' => 'no url'],
                FakeResearchModel::finding(['stance' => 'CONCERN']),
                FakeResearchModel::finding(),
            ],
            ['verdict' => 'likely_suitable'],
        )));

        self::assertSame([0, 2, 3], $answer->modelIndexes);
        self::assertSame(['supports', 'concern', 'neutral'], array_map(static fn ($f) => $f->stance, $answer->findings));
        self::assertSame(['verdict' => 'likely_suitable'], $answer->assessment);
    }
}
