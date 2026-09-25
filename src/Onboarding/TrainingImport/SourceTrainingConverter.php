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
 *  - `public/training-decks/<deck>/<version>/manifest.json` — each slide's
 *    id, title, image, alt text, transcript, videos and links.
 *
 * Slide images live in the public `training-decks` blob container at the same
 * path as in the source checkout (uploaded by the owner, 25 Sep 2026), so a
 * manifest-relative image path becomes {@see ASSET_BASE_URL}/<deck>/<version>/…,
 * and only once the file is confirmed to be in the checkout. The two WHSE videos
 * already carry absolute blob URLs. YouTube embed links become ordinary watch
 * links: they open in a new tab, where an embed URL plays without YouTube's page.
 * The deck PDF (`pdfUrl`) is deck-level and has nowhere to go in a slide list.
 *
 * Question order is the sheet's order and is kept exactly: the assessment PDF
 * mappers place each answer by its position on the paper sheet.
 *
 * Refuses rather than guesses: a question without exactly one correct option,
 * or a module whose question count is not the one the source's own check
 * expects, stops the conversion.
 *
 * @phpstan-type TrainingQuestion array{prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}
 * @phpstan-type TrainingSlide array{id: string, title: string, imageUrl?: string, altText?: string, transcript?: string, media?: list<array{type: string, url: string, mimeType?: string, transcript?: string}>, links?: list<array{text: string, url: string}>}
 * @phpstan-type TrainingRecord array{schema: string, slug: string, documentReference: string, title: string, summary: string, sortOrder: int, content: array{slides: list<TrainingSlide>, passMarkPercent: int, questions: list<TrainingQuestion>}}
 */
final class SourceTrainingConverter
{
    public const SCHEMA = 'tricab.onboarding.source-training/1';

    /** The public container the deck files were uploaded to, keeping the source's paths. */
    public const ASSET_BASE_URL = 'https://tricabv2.blob.core.windows.net/training-decks';

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

    /** @return list<TrainingSlide> */
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

        $deckDir = dirname($manifests[0]);
        $deckUrl = self::ASSET_BASE_URL . '/' . $slug . '/' . basename($deckDir);

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
            $id = self::str($slide['id'] ?? null);
            $converted = ['id' => $id, 'title' => $title];

            $image = trim(self::str($slide['imageUrl'] ?? null));
            if ($image !== '') {
                $converted['imageUrl'] = self::asset($deckDir, $deckUrl, $image, $slug . ' ' . $id . ' image');
            }
            foreach (['altText', 'transcript'] as $key) {
                $text = trim(self::str($slide[$key] ?? null));
                if ($text !== '') {
                    $converted[$key] = $text;
                }
            }

            $media = [];
            foreach (is_array($slide['media'] ?? null) ? $slide['media'] : [] as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException($slug . ' ' . $id . ': a media item is not an object');
                }
                $entry = [
                    'type' => self::str($item['type'] ?? null),
                    'url' => self::asset($deckDir, $deckUrl, trim(self::str($item['url'] ?? null)), $slug . ' ' . $id . ' media'),
                ];
                if (self::str($item['mimeType'] ?? null) !== '') {
                    $entry['mimeType'] = self::str($item['mimeType']);
                }
                if (trim(self::str($item['transcript'] ?? null)) !== '') {
                    $entry['transcript'] = trim(self::str($item['transcript']));
                }
                $media[] = $entry;
            }
            if ($media !== []) {
                $converted['media'] = $media;
            }

            $links = [];
            foreach (is_array($slide['links'] ?? null) ? $slide['links'] : [] as $link) {
                // A link to another slide (`targetSlideId`) has no address to
                // open; none of the three decks has one.
                if (!is_array($link) || trim(self::str($link['url'] ?? null)) === '') {
                    continue;
                }
                $text = trim((string) preg_replace('/\s+/u', ' ', self::str($link['text'] ?? null)));
                $links[] = [
                    'text' => $text === '' ? 'Open link' : $text,
                    'url' => self::youtubeWatchUrl(trim(self::str($link['url']))),
                ];
            }
            if ($links !== []) {
                $converted['links'] = $links;
            }

            $slides[] = $converted;
        }

        return $slides;
    }

    /**
     * An absolute URL is kept; a manifest-relative path must name a file in the
     * checkout and becomes its address in the public container.
     */
    private static function asset(string $deckDir, string $deckUrl, string $path, string $where): string
    {
        if (preg_match('#^https://#i', $path) === 1) {
            return $path;
        }
        $relative = ltrim($path, '/');
        if ($relative === '' || str_contains($relative, '..') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative) === 1) {
            throw new RuntimeException($where . ': not a usable path: ' . $path);
        }
        if (!is_file($deckDir . '/' . $relative)) {
            throw new RuntimeException($where . ': ' . $relative . ' is not in the source checkout');
        }

        return $deckUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** https://www.youtube.com/embed/<id>?… → https://www.youtube.com/watch?v=<id>; anything else unchanged. */
    private static function youtubeWatchUrl(string $url): string
    {
        if (preg_match('~^https://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})(?:[?#].*)?$~', $url, $m) === 1) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }

        return $url;
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
