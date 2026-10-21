<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * The research agent's advisory assessment of a design for one application (C4). It is a preliminary screen,
 * not a certified determination: an engineer signs off, and nothing here changes the design or the rule check.
 *
 * Every pro, con and suggested change cites saved findings by public id. Built from the model's answer by
 * {@see self::fromModel()}, which drops whatever cites only findings that were not kept (off-list domain, or no
 * such finding), and says `insufficient_evidence` when no cited pro or con is left.
 *
 * @phpstan-type Point array{point: string, parameter: string, reasoning: string, findingIds: list<string>}
 * @phpstan-type Change array{change: string, parameter: string, addressesCon: int|null, reasoning: string, findingIds: list<string>}
 * @phpstan-type Check array{check: string, reason: string}
 */
final readonly class ResearchAssessment
{
    public const LIKELY_SUITABLE = 'likely_suitable';
    public const SUITABLE_WITH_CONCERNS = 'suitable_with_concerns';
    public const LIKELY_UNSUITABLE = 'likely_unsuitable';
    public const INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const VERDICTS = [
        self::LIKELY_SUITABLE,
        self::SUITABLE_WITH_CONCERNS,
        self::LIKELY_UNSUITABLE,
        self::INSUFFICIENT_EVIDENCE,
    ];

    private const MAX_ITEMS = 12;

    /**
     * @param list<Point>  $pros
     * @param list<Point>  $cons
     * @param list<Change> $suggestedChanges `addressesCon` is an index into $cons, or null
     * @param list<Check>  $suggestedChecks
     */
    public function __construct(
        public string $verdict,
        public string $summary,
        public array $pros,
        public array $cons,
        public array $suggestedChanges,
        public array $suggestedChecks,
    ) {
    }

    /**
     * @param mixed              $raw        the `assessment` object of the model's answer
     * @param array<int, string> $findingIds the model's finding index => public id of the finding that was kept
     */
    public static function fromModel(mixed $raw, array $findingIds): self
    {
        if (!is_array($raw)) {
            return new self(self::INSUFFICIENT_EVIDENCE, 'The agent gave no assessment.', [], [], [], []);
        }

        $pros = self::points($raw['pros'] ?? null, $findingIds)[0];
        [$cons, $conIndex] = self::points($raw['cons'] ?? null, $findingIds);

        $changes = [];
        foreach (self::items($raw['suggestedChanges'] ?? null) as $item) {
            $change = self::text($item['change'] ?? null, 1000);
            $ids = self::cited($item['findingRefs'] ?? null, $findingIds);
            if ($change === null || $ids === []) {
                continue;
            }
            $addresses = self::index($item['addressesCon'] ?? null);
            $changes[] = [
                'change' => $change,
                'parameter' => self::text($item['parameter'] ?? null, 200) ?? 'general',
                'addressesCon' => $addresses === null ? null : ($conIndex[$addresses] ?? null),
                'reasoning' => self::text($item['reasoning'] ?? null, 2000) ?? '',
                'findingIds' => $ids,
            ];
        }

        $checks = [];
        foreach (self::items($raw['suggestedChecks'] ?? null) as $item) {
            $check = self::text($item['check'] ?? null, 1000);
            if ($check !== null) {
                $checks[] = ['check' => $check, 'reason' => self::text($item['reason'] ?? null, 2000) ?? ''];
            }
        }

        $verdict = is_string($raw['verdict'] ?? null) ? strtolower(trim($raw['verdict'])) : '';
        if (!in_array($verdict, self::VERDICTS, true) || ($pros === [] && $cons === [])) {
            $verdict = self::INSUFFICIENT_EVIDENCE;
        }

        return new self(
            $verdict,
            self::text($raw['summary'] ?? null, 2000) ?? '',
            $pros,
            $cons,
            array_slice($changes, 0, self::MAX_ITEMS),
            array_slice($checks, 0, self::MAX_ITEMS),
        );
    }

    /**
     * Read back from `assessment_json`. Null when the JSON is not an assessment.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $verdict = $data['verdict'] ?? null;
        if (!is_string($verdict) || !in_array($verdict, self::VERDICTS, true)) {
            return null;
        }
        $ids = static function (mixed $value): array {
            $out = [];
            foreach (is_array($value) ? $value : [] as $id) {
                if (is_string($id) && $id !== '') {
                    $out[] = $id;
                }
            }

            return $out;
        };
        $points = static function (mixed $value) use ($ids): array {
            $out = [];
            foreach (self::items($value) as $item) {
                $out[] = [
                    'point' => self::text($item['point'] ?? null, 1000) ?? '',
                    'parameter' => self::text($item['parameter'] ?? null, 200) ?? 'general',
                    'reasoning' => self::text($item['reasoning'] ?? null, 2000) ?? '',
                    'findingIds' => $ids($item['findingIds'] ?? null),
                ];
            }

            return $out;
        };
        $changes = [];
        foreach (self::items($data['suggestedChanges'] ?? null) as $item) {
            $changes[] = [
                'change' => self::text($item['change'] ?? null, 1000) ?? '',
                'parameter' => self::text($item['parameter'] ?? null, 200) ?? 'general',
                'addressesCon' => self::index($item['addressesCon'] ?? null),
                'reasoning' => self::text($item['reasoning'] ?? null, 2000) ?? '',
                'findingIds' => $ids($item['findingIds'] ?? null),
            ];
        }
        $checks = [];
        foreach (self::items($data['suggestedChecks'] ?? null) as $item) {
            $checks[] = ['check' => self::text($item['check'] ?? null, 1000) ?? '', 'reason' => self::text($item['reason'] ?? null, 2000) ?? ''];
        }

        return new self(
            $verdict,
            self::text($data['summary'] ?? null, 2000) ?? '',
            $points($data['pros'] ?? null),
            $points($data['cons'] ?? null),
            $changes,
            $checks,
        );
    }

    /**
     * @return array{verdict: string, summary: string, pros: list<Point>, cons: list<Point>, suggestedChanges: list<Change>, suggestedChecks: list<Check>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'pros' => $this->pros,
            'cons' => $this->cons,
            'suggestedChanges' => $this->suggestedChanges,
            'suggestedChecks' => $this->suggestedChecks,
        ];
    }

    /**
     * Pros or cons that still cite a kept finding, and where each kept one came from in the model's list.
     *
     * @param array<int, string> $findingIds
     *
     * @return array{0: list<Point>, 1: array<int, int>} the points, and model index => index in the points
     */
    private static function points(mixed $value, array $findingIds): array
    {
        $points = [];
        $index = [];
        foreach (self::items($value) as $i => $item) {
            $point = self::text($item['point'] ?? null, 1000);
            $ids = self::cited($item['findingRefs'] ?? null, $findingIds);
            if ($point === null || $ids === [] || count($points) >= self::MAX_ITEMS) {
                continue;
            }
            $index[$i] = count($points);
            $points[] = [
                'point' => $point,
                'parameter' => self::text($item['parameter'] ?? null, 200) ?? 'general',
                'reasoning' => self::text($item['reasoning'] ?? null, 2000) ?? '',
                'findingIds' => $ids,
            ];
        }

        return [$points, $index];
    }

    /**
     * The public ids of the kept findings that $refs names, in order, once each.
     *
     * @param array<int, string> $findingIds
     *
     * @return list<string>
     */
    private static function cited(mixed $refs, array $findingIds): array
    {
        $ids = [];
        foreach (is_array($refs) ? $refs : [] as $ref) {
            $index = self::index($ref);
            if ($index !== null && isset($findingIds[$index]) && !in_array($findingIds[$index], $ids, true)) {
                $ids[] = $findingIds[$index];
            }
        }

        return $ids;
    }

    /**
     * The array items of a list, keyed by their position in it; anything else is nothing.
     *
     * @return array<int, array<array-key, mixed>>
     */
    private static function items(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_filter($value, 'is_array');
    }

    private static function index(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d{1,4}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
