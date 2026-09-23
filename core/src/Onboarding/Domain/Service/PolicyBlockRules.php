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

    public const TYPES = ['heading', 'paragraph', 'list', 'callout', 'table', 'comparisonTable'];
    public const LIST_STYLES = ['bullet', 'number', 'lower-roman', 'upper-roman', 'lower-alpha', 'upper-alpha'];
    public const CALLOUT_TONES = ['note', 'warning'];

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

        return match ($type) {
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
