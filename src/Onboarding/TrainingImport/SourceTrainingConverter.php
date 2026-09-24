<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\TrainingImport;

use RuntimeException;
use TriCab\Core\Onboarding\Domain\Service\TrainingAuthoringRules;

/**
 * Reads the source's three TriCab training modules into the platform's
 * training shape (5.4 prerequisite): title, summary, pass mark, the slide list
 * from each deck manifest, and the questions with their HR-approved answer key.
 *
 * Sources, all in `tricab-training-vercel`:
 *  - `db/training-content.sql` — the modules (by document reference), the
 *    Respect at Work and Sexual Harassment quizzes as JSON, the WHSE quiz as
 *    SQL tuples, and an explanation backfill applied on top;
 *  - `public/training-decks/<deck>/<version>/manifest.json` — slide ids and
 *    titles. Images and transcripts are not ported (a slide is id + title).
 *
 * Question order is the sheet's order and is kept exactly: the assessment PDF
 * mappers place each answer by its position on the paper sheet.
 *
 * Refuses rather than guesses: a question without exactly one correct option,
 * or a module whose question count is not the one the source's own check
 * expects, stops the conversion.
 *
 * @phpstan-type TrainingQuestion array{prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}
 * @phpstan-type TrainingRecord array{schema: string, slug: string, documentReference: string, title: string, summary: string, sortOrder: int, content: array{slides: list<array{id: string, title: string}>, passMarkPercent: int, questions: list<TrainingQuestion>}}
 */
final class SourceTrainingConverter
{
    public const SCHEMA = 'tricab.onboarding.source-training/1';

    /** document reference => [slug (the deck id), expected question count] */
    public const MODULES = [
        'HR-DOC-009-V010' => ['corporate-whse-induction', 17],
        'HR-DOC-098-V000' => ['respect-at-work', 22],
        'HR-DOC-094-V000' => ['workplace-sexual-harassment', 22],
    ];

    /**
     * @return list<TrainingRecord> one record per module, in the source's sort order
     */
    public function convert(string $sourceRoot): array
    {
        $sql = @file_get_contents($sourceRoot . '/db/training-content.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('db/training-content.sql not found under ' . $sourceRoot);
        }

        $modules = self::modules($sql);
        $questions = self::jsonQuizzes($sql) + ['HR-DOC-009-V010' => self::whseQuiz($sql)];
        $explanations = self::explanations($sql);

        $records = [];
        foreach (self::MODULES as $reference => [$slug, $expected]) {
            $module = $modules[$reference] ?? throw new RuntimeException($reference . ': not in source_modules');
            $quiz = $questions[$reference] ?? throw new RuntimeException($reference . ': no questions found');
            foreach ($explanations[$reference] ?? [] as $sortOrder => $reason) {
                if (isset($quiz[$sortOrder])) {
                    $quiz[$sortOrder]['correctReason'] = $reason;
                }
            }
            ksort($quiz);
            if (count($quiz) !== $expected) {
                throw new RuntimeException(sprintf('%s: %d questions, the source expects %d', $reference, count($quiz), $expected));
            }

            $records[] = [
                'schema' => self::SCHEMA,
                'slug' => $slug,
                'documentReference' => $reference,
                'title' => $module['title'],
                'summary' => $module['summary'],
                'sortOrder' => $module['sortOrder'],
                'content' => [
                    'slides' => self::slides($sourceRoot, $slug, $reference),
                    'passMarkPercent' => $module['passMark'],
                    'questions' => array_values($quiz),
                ],
            ];
        }
        usort($records, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return $records;
    }

    /** @return array<string, array{title: string, summary: string, sortOrder: int, passMark: int}> */
    private static function modules(string $sql): array
    {
        $block = self::between($sql, 'WITH source_modules', 'INSERT INTO modules');
        preg_match_all("/\\(\\s*'((?:[^']|'')*)',\\s*'(HR-DOC-\\d+-V\\d+)',\\s*'((?:[^']|'')*)',\\s*(\\d+),\\s*(\\d+),\\s*(?:TRUE|FALSE)\\s*\\)/", $block, $m, PREG_SET_ORDER);
        $modules = [];
        foreach ($m as [, $title, $reference, $summary, $sort, $pass]) {
            $modules[$reference] = ['title' => self::unquote($title), 'summary' => self::unquote($summary), 'sortOrder' => (int) $sort, 'passMark' => (int) $pass];
        }

        return $modules;
    }

    /** @return array<string, array<int, TrainingQuestion>> */
    private static function jsonQuizzes(string $sql): array
    {
        preg_match_all('/\(\s*\'(HR-DOC-\d+-V\d+)\',\s*\$quiz\$(.*?)\$quiz\$(?:::jsonb)?\s*\)/s', $sql, $m, PREG_SET_ORDER);
        $quizzes = [];
        foreach ($m as [, $reference, $json]) {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException($reference . ': quiz is not a list');
            }
            foreach ($decoded as $index => $question) {
                if (!is_array($question)) {
                    throw new RuntimeException($reference . ': a question is not an object');
                }
                $sortOrder = is_int($question['sortOrder'] ?? null) ? $question['sortOrder'] : $index + 1;
                $quizzes[$reference][$sortOrder] = self::question(
                    $reference . ' Q' . $sortOrder,
                    self::str($question['question'] ?? null),
                    is_string($question['reason'] ?? null) ? $question['reason'] : '',
                    $question['options'] ?? null,
                );
            }
        }

        return $quizzes;
    }

    /** @return array<int, TrainingQuestion> */
    private static function whseQuiz(string $sql): array
    {
        $block = self::between($sql, 'WITH whse_questions', 'INSERT INTO questions');
        preg_match_all("/\\(\\s*(\\d+),\\s*'((?:[^']|'')*)',\\s*'((?:[^']|'')*)',\\s*'((?:[^']|'')*)'::jsonb\\s*\\)/s", $block, $m, PREG_SET_ORDER);
        $quiz = [];
        foreach ($m as [, $sort, $text, $reason, $options]) {
            $quiz[(int) $sort] = self::question(
                'HR-DOC-009-V010 Q' . $sort,
                self::unquote($text),
                self::unquote($reason),
                json_decode(self::unquote($options), true, 512, JSON_THROW_ON_ERROR),
            );
        }

        return $quiz;
    }

    /** @return array<string, array<int, string>> */
    private static function explanations(string $sql): array
    {
        $block = self::between($sql, 'WITH explanations', 'UPDATE questions');
        preg_match_all("/\\(\\s*'(HR-DOC-\\d+-V\\d+)',\\s*(\\d+),\\s*'((?:[^']|'')*)'\\s*\\)/", $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $reference, $sort, $reason]) {
            $out[$reference][(int) $sort] = self::unquote($reason);
        }

        return $out;
    }

    /**
     * @return array{prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}
     */
    private static function question(string $where, string $prompt, string $reason, mixed $options): array
    {
        if (trim($prompt) === '' || !is_array($options)) {
            throw new RuntimeException($where . ': no wording or no options');
        }
        $converted = [];
        $correct = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                throw new RuntimeException($where . ': an option is not an object');
            }
            $key = strtolower(trim(self::str($option['label'] ?? null)));
            $label = trim(self::str($option['text'] ?? null));
            if (preg_match('/^[a-z0-9]{1,40}$/', $key) !== 1 || $label === '') {
                throw new RuntimeException($where . ': an option has no letter or no text');
            }
            $converted[] = ['key' => $key, 'label' => $label];
            if (($option['correct'] ?? false) === true) {
                $correct[] = $key;
            }
        }
        if (count($correct) !== 1) {
            throw new RuntimeException(sprintf('%s: %d correct options, exactly one is required', $where, count($correct)));
        }

        return [
            'prompt' => trim($prompt),
            'options' => $converted,
            'correctOptionKey' => $correct[0],
            'correctReason' => trim($reason) === '' ? null : trim($reason),
        ];
    }

    /** @return list<array{id: string, title: string}> */
    private static function slides(string $sourceRoot, string $slug, string $reference): array
    {
        $manifests = glob($sourceRoot . '/public/training-decks/' . $slug . '/*/manifest.json') ?: [];
        if (count($manifests) !== 1) {
            throw new RuntimeException(sprintf('%s: expected one deck manifest, found %d', $slug, count($manifests)));
        }
        $manifest = json_decode((string) file_get_contents($manifests[0]), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['moduleReference'] ?? null) !== $reference || !is_array($manifest['slides'] ?? null)) {
            throw new RuntimeException($slug . ': the manifest is not this module\'s');
        }

        $slides = [];
        foreach ($manifest['slides'] as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $number = is_int($slide['number'] ?? null) ? $slide['number'] : count($slides) + 1;
            $title = trim((string) preg_replace('/\s+/u', ' ', self::str($slide['title'] ?? null)));
            if ($title === '') {
                $title = 'Slide ' . $number;
            }
            if (mb_strlen($title) > TrainingAuthoringRules::MAX_SLIDE_TITLE_LENGTH) {
                $title = rtrim(mb_substr($title, 0, TrainingAuthoringRules::MAX_SLIDE_TITLE_LENGTH - 1)) . '…';
            }
            $slides[] = ['id' => self::str($slide['id'] ?? null), 'title' => $title];
        }

        return $slides;
    }

    private static function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $to = $from === false ? false : strpos($haystack, $end, $from);
        if ($from === false || $to === false) {
            throw new RuntimeException('Section not found in training-content.sql: ' . $start);
        }

        return substr($haystack, $from, $to - $from);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function unquote(string $sql): string
    {
        return str_replace("''", "'", $sql);
    }
}
