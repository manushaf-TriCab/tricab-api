<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * What an HR author may put in a policy version's wording (4.4).
 *
 * Ported from the source's `api/_lib/policy-content.ts`: six block types, the
 * same limits, and the same refusal of everything else. A `{type: "html"}`
 * block is refused rather than stored, as the source's own test demands, because
 * policy wording is written in an admin browser and shown to every new starter.
 * The trainee renderer emits text nodes whatever it is given; this is the second
 * lock, and the one that keeps executable content out of the database at all.
 *
 * ## Strict here, forgiving on the way out
 *
 * {@see \TriCab\Core\Onboarding\Domain\ValueObject\PolicyContent} keeps unknown
 * blocks when it reads, so a clause is never silently dropped from a document
 * somebody is about to accept. This class is the opposite end: the author is
 * present, can fix the problem, and is told exactly which block is wrong.
 *
 * The source quietly turned an unknown list style into bullets and an
 * out-of-range start into 1. Here both are refused: an author who asked for
 * roman numerals and got bullets finds out from a new starter, not from the
 * editor.
 *
 * ## Emphasis is data beside the text, never markup in it
 *
 * Bold and italic (decision D2 of the handbook import) are a block-level
 * `marks` map from a text field's path to ranges:
 * `{"text": [{"start": 2, "end": 18, "mark": "bold"}]}`. Paths are `text`
 * (paragraph, callout), `items.N` (list), `rows.R.C` (table) and
 * `rows.R.always.N` / `rows.R.never.N` (comparison). Offsets are code points,
 * end exclusive. The text itself stays plain, so a renderer that knows nothing
 * of marks still shows every word, and there is no string anywhere that a
 * browser could be persuaded to parse.
 *
 * Headings, captions, titles and table headers take no marks: they are already
 * set apart. Offsets are taken against the text as sent and moved with it when
 * it is trimmed or a blank list line is dropped, so a mark never ends up on
 * the wrong words.
 *
 * Pure: no database, no request, no clock.
 */
final class PolicyBlockRules
{
    public const MAX_BLOCKS = 160;
    public const MAX_TEXT_LENGTH = 20000;
    public const MAX_SHORT_TEXT_LENGTH = 240;
    public const MAX_LIST_ITEMS = 100;
    public const MAX_TABLE_ROWS = 50;
    public const MAX_TABLE_COLUMNS = 6;
    public const MAX_DECLARATION_LENGTH = 2000;
    public const MAX_REFERENCE_LENGTH = 80;
    public const MAX_LABEL_LENGTH = 40;

    public const TYPES = ['heading', 'paragraph', 'list', 'callout', 'table', 'comparisonTable'];
    public const LIST_STYLES = ['bullet', 'number', 'lower-roman', 'upper-roman', 'lower-alpha', 'upper-alpha'];
    public const CALLOUT_TONES = ['note', 'warning'];
    public const MARKS = ['bold', 'italic'];
    public const MAX_MARKS_PER_FIELD = 200;

    /**
     * @return list<array<string, mixed>> the blocks in their stored shape
     *
     * @throws InvalidOnboardingValue naming the block that is wrong
     */
    public static function normalise(mixed $blocks): array
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw InvalidOnboardingValue::for('policy blocks', 'not a list');
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            throw InvalidOnboardingValue::for('policy blocks', sprintf('more than %d blocks', self::MAX_BLOCKS));
        }

        $normalised = [];
        foreach ($blocks as $index => $block) {
            $normalised[] = self::block($block, $index + 1);
        }

        return $normalised;
    }

    /**
     * Null when the author left it empty, which means "use the default".
     *
     * @throws InvalidOnboardingValue
     */
    public static function declaration(?string $declaration): ?string
    {
        $trimmed = $declaration === null ? '' : trim($declaration);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > self::MAX_DECLARATION_LENGTH) {
            throw InvalidOnboardingValue::for('declaration', sprintf('longer than %d characters', self::MAX_DECLARATION_LENGTH));
        }

        return $trimmed;
    }

    /**
     * A document reference or a date label as printed ("September-25"). Null
     * when empty.
     *
     * @throws InvalidOnboardingValue
     */
    public static function label(?string $value, string $what, int $max): ?string
    {
        $trimmed = $value === null ? '' : trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $max) {
            throw InvalidOnboardingValue::for($what, sprintf('longer than %d characters', $max));
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(mixed $block, int $number): array
    {
        if (!is_array($block)) {
            throw InvalidOnboardingValue::for('policy block ' . $number, 'not an object');
        }
        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw InvalidOnboardingValue::forOneOf('type of policy block ' . $number, is_string($type) ? $type : '(missing)', self::TYPES);
        }
        $where = sprintf('policy block %d (%s)', $number, $type);

        $normalised = match ($type) {
            'heading' => [
                'type' => 'heading',
                'level' => self::headingLevel($block['level'] ?? null, $where),
                'text' => self::text($block['text'] ?? null, $where . ' text', self::MAX_SHORT_TEXT_LENGTH),
            ],
            'paragraph' => [
                'type' => 'paragraph',
                'text' => self::text($block['text'] ?? null, $where . ' text', self::MAX_TEXT_LENGTH),
            ],
            'list' => [
                'type' => 'list',
                'style' => self::oneOf($block['style'] ?? 'bullet', self::LIST_STYLES, $where . ' style'),
                'start' => self::listStart($block['start'] ?? 1, $where),
                'items' => self::lines($block['items'] ?? null, $where . ' items', self::MAX_LIST_ITEMS, 1),
            ],
            'callout' => [
                'type' => 'callout',
                'tone' => self::oneOf($block['tone'] ?? 'note', self::CALLOUT_TONES, $where . ' tone'),
                'title' => self::optionalText($block['title'] ?? null, $where . ' title', self::MAX_SHORT_TEXT_LENGTH),
                'text' => self::text($block['text'] ?? null, $where . ' text', self::MAX_TEXT_LENGTH),
            ],
            'table' => self::table($block, $where),
            default => self::comparisonTable($block, $where),
        };

        $marks = self::marks($block, $normalised, $where);
        if ($marks !== []) {
            $normalised['marks'] = $marks;
        }

        return $normalised;
    }

    /**
     * @param array<array-key, mixed> $raw        the block as sent
     * @param array<string, mixed>    $normalised the block as it will be stored
     *
     * @return array<string, list<array{start: int, end: int, mark: string}>>
     */
    private static function marks(array $raw, array $normalised, string $where): array
    {
        $marks = $raw['marks'] ?? null;
        if ($marks === null || $marks === []) {
            return [];
        }
        if (!is_array($marks) || array_is_list($marks)) {
            throw InvalidOnboardingValue::for($where . ' marks', 'not a map of field to ranges');
        }

        $stored = [];
        foreach ($marks as $path => $ranges) {
            $path = (string) $path;
            $target = self::markTarget($raw, $normalised, $path);
            if ($target === null) {
                throw InvalidOnboardingValue::for($where . ' marks', sprintf('"%s" is not a text that can be emphasised', $path));
            }
            [$storedPath, $rawText, $text] = $target;
            $lead = mb_strlen($rawText) - mb_strlen(ltrim($rawText));
            $cleaned = self::ranges($ranges, $lead, mb_strlen($text), sprintf('%s marks "%s"', $where, $path));
            if ($cleaned !== []) {
                $stored[$storedPath] = $cleaned;
            }
        }
        ksort($stored, SORT_NATURAL);

        return $stored;
    }

    /**
     * Resolves a mark path to the field it emphasises: the path it will be
     * stored under, the text as sent and the text as stored. Null when the path
     * names no emphasisable text, or a blank list line the rules dropped.
     *
     * @param array<array-key, mixed> $raw
     * @param array<string, mixed>    $normalised
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function markTarget(array $raw, array $normalised, string $path): ?array
    {
        $type = $normalised['type'];
        $steps = explode('.', $path);
        $index = static fn (string $step): ?int => preg_match('/^(0|[1-9][0-9]{0,3})$/', $step) === 1 ? (int) $step : null;

        if (($type === 'paragraph' || $type === 'callout') && $path === 'text') {
            return is_string($raw['text'] ?? null) && is_string($normalised['text']) ? ['text', $raw['text'], $normalised['text']] : null;
        }

        if ($type === 'list' && count($steps) === 2 && $steps[0] === 'items' && ($n = $index($steps[1])) !== null) {
            $kept = self::keptLine($raw['items'] ?? null, $n);
            $items = $normalised['items'];

            return $kept !== null && is_array($items) && is_string($items[$kept[0]] ?? null)
                ? ['items.' . $kept[0], $kept[1], $items[$kept[0]]]
                : null;
        }

        if ($type === 'table' && count($steps) === 3 && $steps[0] === 'rows' && ($r = $index($steps[1])) !== null && ($c = $index($steps[2])) !== null) {
            $rawRows = $raw['rows'] ?? null;
            $rawCell = is_array($rawRows) && is_array($rawRows[$r] ?? null) ? ($rawRows[$r][$c] ?? null) : null;
            $rows = $normalised['rows'];
            $cell = is_array($rows) && is_array($rows[$r] ?? null) ? ($rows[$r][$c] ?? null) : null;

            return is_string($rawCell) && is_string($cell) && $cell !== '' ? [$path, $rawCell, $cell] : null;
        }

        if ($type === 'comparisonTable' && count($steps) === 4 && $steps[0] === 'rows' && in_array($steps[2], ['always', 'never'], true)
            && ($r = $index($steps[1])) !== null && ($n = $index($steps[3])) !== null) {
            $rawRows = $raw['rows'] ?? null;
            $rawRow = is_array($rawRows) ? ($rawRows[$r] ?? null) : null;
            $kept = self::keptLine(is_array($rawRow) ? ($rawRow[$steps[2]] ?? null) : null, $n);
            $rows = $normalised['rows'];
            $lines = is_array($rows) && is_array($rows[$r] ?? null) ? ($rows[$r][$steps[2]] ?? null) : null;

            return $kept !== null && is_array($lines) && is_string($lines[$kept[0]] ?? null)
                ? [sprintf('rows.%d.%s.%d', $r, $steps[2], $kept[0]), $kept[1], $lines[$kept[0]]]
                : null;
        }

        return null;
    }

    /**
     * Where raw line $n lands once blank lines are dropped, and its raw text.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function keptLine(mixed $lines, int $n): ?array
    {
        if (!is_array($lines) || !array_is_list($lines) || !is_string($lines[$n] ?? null) || trim($lines[$n]) === '') {
            return null;
        }
        $kept = 0;
        for ($i = 0; $i < $n; $i++) {
            if (is_string($lines[$i]) && trim($lines[$i]) !== '') {
                $kept++;
            }
        }

        return [$kept, $lines[$n]];
    }

    /**
     * Validates one field's ranges, moves them by what trimming removed from
     * the front, clips them to the stored text, and merges overlaps of the same
     * mark. A range left empty by trimming is dropped: it covered only spaces.
     *
     * @return list<array{start: int, end: int, mark: string}>
     */
    private static function ranges(mixed $ranges, int $lead, int $length, string $where): array
    {
        if (!is_array($ranges) || !array_is_list($ranges)) {
            throw InvalidOnboardingValue::for($where, 'not a list of ranges');
        }
        if (count($ranges) > self::MAX_MARKS_PER_FIELD) {
            throw InvalidOnboardingValue::for($where, sprintf('more than %d ranges', self::MAX_MARKS_PER_FIELD));
        }

        $byMark = [];
        foreach ($ranges as $i => $range) {
            $label = sprintf('%s range %d', $where, $i + 1);
            if (!is_array($range)) {
                throw InvalidOnboardingValue::for($label, 'not an object');
            }
            $mark = self::oneOf($range['mark'] ?? null, self::MARKS, $label . ' mark');
            $start = $range['start'] ?? null;
            $end = $range['end'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start) {
                throw InvalidOnboardingValue::for($label, 'start and end must be whole numbers with start before end');
            }
            $start = max(0, $start - $lead);
            $end = min($length, $end - $lead);
            if ($end > $start) {
                $byMark[$mark][] = [$start, $end];
            }
        }

        $merged = [];
        foreach ($byMark as $mark => $spans) {
            usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $current = null;
            foreach ($spans as $span) {
                if ($current !== null && $span[0] <= $current[1]) {
                    $current[1] = max($current[1], $span[1]);
                    continue;
                }
                if ($current !== null) {
                    $merged[] = ['start' => $current[0], 'end' => $current[1], 'mark' => (string) $mark];
                }
                $current = $span;
            }
            $merged[] = ['start' => $current[0], 'end' => $current[1], 'mark' => (string) $mark];
        }
        usort($merged, static fn (array $a, array $b): int => [$a['start'], $a['mark']] <=> [$b['start'], $b['mark']]);

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function table(array $block, string $where): array
    {
        $headers = self::cells($block['headers'] ?? null, $where . ' headers', self::MAX_TABLE_COLUMNS);
        if ($headers === []) {
            throw InvalidOnboardingValue::required($where . ' column', 'a table needs at least one column.');
        }

        $rawRows = $block['rows'] ?? null;
        if (!is_array($rawRows) || !array_is_list($rawRows) || $rawRows === []) {
            throw InvalidOnboardingValue::required($where . ' row', 'a table needs at least one row.');
        }
        if (count($rawRows) > self::MAX_TABLE_ROWS) {
            throw InvalidOnboardingValue::for($where . ' rows', sprintf('more than %d rows', self::MAX_TABLE_ROWS));
        }

        $rows = [];
        foreach ($rawRows as $index => $row) {
            $cells = self::cells($row, sprintf('%s row %d', $where, $index + 1), self::MAX_TABLE_COLUMNS);
            // The source's test: a row that does not match the header count is
            // refused, because a shifted cell puts an obligation in the wrong column.
            if (count($cells) !== count($headers)) {
                throw InvalidOnboardingValue::for(
                    sprintf('%s row %d', $where, $index + 1),
                    sprintf('%d cells where the table has %d columns', count($cells), count($headers)),
                );
            }
            $rows[] = $cells;
        }

        return [
            'type' => 'table',
            'caption' => self::optionalText($block['caption'] ?? null, $where . ' caption', self::MAX_SHORT_TEXT_LENGTH),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<array-key, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function comparisonTable(array $block, string $where): array
    {
        $rawRows = $block['rows'] ?? null;
        if (!is_array($rawRows) || !array_is_list($rawRows) || $rawRows === []) {
            throw InvalidOnboardingValue::required($where . ' row', 'a comparison needs at least one row.');
        }
        if (count($rawRows) > self::MAX_TABLE_ROWS) {
            throw InvalidOnboardingValue::for($where . ' rows', sprintf('more than %d rows', self::MAX_TABLE_ROWS));
        }

        $rows = [];
        foreach ($rawRows as $index => $row) {
            $label = sprintf('%s row %d', $where, $index + 1);
            if (!is_array($row)) {
                throw InvalidOnboardingValue::for($label, 'not an object');
            }
            $always = self::lines($row['always'] ?? [], $label . ' "always"', self::MAX_LIST_ITEMS, 0);
            $never = self::lines($row['never'] ?? [], $label . ' "never"', self::MAX_LIST_ITEMS, 0);
            if ($always === [] && $never === []) {
                throw InvalidOnboardingValue::required($label, 'each row needs something to always or never do.');
            }
            $rows[] = ['always' => $always, 'never' => $never];
        }

        return [
            'type' => 'comparisonTable',
            'caption' => self::optionalText($block['caption'] ?? null, $where . ' caption', self::MAX_SHORT_TEXT_LENGTH),
            'alwaysHeading' => self::optionalText($block['alwaysHeading'] ?? null, $where . ' heading', self::MAX_SHORT_TEXT_LENGTH) ?? 'Always',
            'neverHeading' => self::optionalText($block['neverHeading'] ?? null, $where . ' heading', self::MAX_SHORT_TEXT_LENGTH) ?? 'Never',
            'rows' => $rows,
        ];
    }

    private static function headingLevel(mixed $level, string $where): int
    {
        if ($level === null) {
            return 2;
        }
        if ($level !== 2 && $level !== 3) {
            throw InvalidOnboardingValue::forOneOf($where . ' level', is_scalar($level) ? (string) $level : '(not a number)', ['2', '3']);
        }

        return $level;
    }

    private static function listStart(mixed $start, string $where): int
    {
        if (!is_int($start) || $start < 1 || $start > 100) {
            throw InvalidOnboardingValue::for($where . ' start', is_scalar($start) ? (string) $start : '(not a number)');
        }

        return $start;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $where): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw InvalidOnboardingValue::forOneOf($where, is_string($value) ? $value : '(missing)', $allowed);
        }

        return $value;
    }

    private static function text(mixed $value, string $where, int $max): string
    {
        $text = self::optionalText($value, $where, $max);
        if ($text === null) {
            throw InvalidOnboardingValue::required($where, 'it cannot be empty.');
        }

        return $text;
    }

    private static function optionalText(mixed $value, string $where, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($where, '(not text)');
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('longer than %d characters', $max));
        }

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Non-empty lines; blank ones are dropped because an editor with one line
     * per item always leaves a trailing one.
     *
     * @return list<string>
     */
    private static function lines(mixed $value, string $where, int $max, int $min): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw InvalidOnboardingValue::for($where, 'not a list');
        }
        $lines = [];
        foreach ($value as $line) {
            $text = self::optionalText($line, $where, self::MAX_TEXT_LENGTH);
            if ($text !== null) {
                $lines[] = $text;
            }
        }
        if (count($lines) < $min) {
            throw InvalidOnboardingValue::required($where, 'add at least one item.');
        }
        if (count($lines) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('more than %d items', $max));
        }

        return $lines;
    }

    /**
     * Table cells keep their position, so an empty cell stays as "".
     *
     * @return list<string>
     */
    private static function cells(mixed $value, string $where, int $max): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw InvalidOnboardingValue::for($where, 'not a list');
        }
        if (count($value) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('more than %d columns', $max));
        }
        $cells = [];
        foreach ($value as $cell) {
            $cells[] = self::optionalText($cell, $where, self::MAX_TEXT_LENGTH) ?? '';
        }

        return $cells;
    }
}
