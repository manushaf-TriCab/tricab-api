<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\HandbookImport;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use RuntimeException;

/**
 * Turns one section of the TriCab Employee Handbook's controlled HTML rendition
 * into policy blocks the platform can store (handbook policy import, Step 1).
 *
 * The source (`tricab-training-vercel/public/policy-library/sections/*.html`)
 * is an extraction from a PDF. It is well-formed, but it carries the PDF with
 * it: running page headers repeated as bold paragraphs, table cells broken with
 * `<br>` wherever the PDF wrapped a line, bullet glyphs in their own table
 * columns. This class undoes those mechanically and **says so** — every change
 * that is not a straight tag-to-block mapping is recorded in the report, because
 * HR re-checks the converted text against the PDF before it is published and
 * needs to know where to look.
 *
 * ## Emphasis
 *
 * Block text stays a plain string. Bold and italic are recorded beside it, in a
 * block-level `marks` map from the field's path (`text`, `items.3`,
 * `rows.2.1`, `rows.0.never.0`) to ranges of `{start, end, mark}` — code-point
 * offsets, end exclusive — the shape `PolicyBlockRules` validates and keeps. A
 * renderer that does not know `marks` still shows every word. Headings and
 * table headers carry no marks: they are already bold.
 *
 * ## The acknowledgement panel
 *
 * The source's `<aside class="policy-acknowledgement">` is a paper sign-off
 * sheet. Its statements become the version's `declaration` — the wording a
 * trainee accepts, stored verbatim on the acknowledgement row. Its blank fields
 * (Name, Signature, Date) and anything that asks the reader to choose, tick or
 * attach are not declaration wording: they are reported as belonging to the
 * separate signed form (decision D4), and left out.
 *
 * Pure apart from reading the string it is given: no files, no database.
 *
 * @phpstan-type Report array{
 *     blockCounts: array<string, int>,
 *     wordCoverage: array{sourceWords: int, missing: list<string>, coverage: float},
 *     demotedH4: list<string>,
 *     flattenedNestedLists: list<string>,
 *     joinedListParagraphs: int,
 *     lineBreaksJoined: int,
 *     droppedUnderline: list<string>,
 *     droppedLinks: list<array{text: string, href: string}>,
 *     droppedHeadingEmphasis: int,
 *     droppedRunningHeaders: list<string>,
 *     comparisonTables: int,
 *     declarationTitle: ?string,
 *     declarationShownAsBody: bool,
 *     signOffFields: list<string>,
 *     formItems: list<string>,
 *     declarationNeedsReview: list<string>,
 *     unknownElements: list<string>,
 * }
 */
final class HandbookPolicyConverter
{
    /** A paragraph in the sign-off sheet that is a blank field to fill in. */
    private const FIELD_LABEL = '/^(full name|name|signature|date|division\/region)(\s*\(print\))?\s*:?$/iu';

    /**
     * Sign-off statements that are a choice or an attachment rather than
     * something to agree to. They need the form, not a tick box.
     */
    private const FORM_ITEM = [
        '/^I have attached/iu',
        '/^Yes By selecting Yes/iu',
    ];

    /**
     * A heading asking the reader to tick starts a group of options, and
     * everything after it in the panel belongs to that group. The Code of
     * Conduct's conflict-of-interest block is two exclusive answers — "not in
     * a conflict", ticked together with the two undertakings under it, or "in
     * a conflict", with details — so none of those lines is something every
     * reader agrees to (confirmed by the owner, 23 Sep 2026).
     */
    private const TICK_GROUP = '/\(please indicate by ticking/iu';

    /** @var list<array<string, mixed>> */
    private array $blocks = [];
    /** @var list<string> */
    private array $declaration = [];
    /** @var list<string> */
    private array $demotedH4 = [];
    /** @var list<string> */
    private array $flattenedNestedLists = [];
    private int $joinedListParagraphs = 0;
    private int $lineBreaksJoined = 0;
    /** @var list<string> */
    private array $droppedUnderline = [];
    /** @var list<array{text: string, href: string}> */
    private array $droppedLinks = [];
    private int $droppedHeadingEmphasis = 0;
    /** @var list<string> */
    private array $droppedRunningHeaders = [];
    private int $comparisonTables = 0;
    private ?string $declarationTitle = null;
    private bool $inTickGroup = false;
    private bool $declarationShownAsBody = false;
    /** @var list<string> */
    private array $signOffFields = [];
    /** @var list<string> */
    private array $formItems = [];
    /** @var list<string> */
    private array $declarationNeedsReview = [];
    /** @var list<string> */
    private array $unknownElements = [];

    /**
     * @return array{blocks: list<array<string, mixed>>, declaration: ?string, report: Report}
     */
    public static function convert(string $html): array
    {
        return (new self())->run($html);
    }

    /**
     * @return array{blocks: list<array<string, mixed>>, declaration: ?string, report: Report}
     */
    private function run(string $html): array
    {
        $content = self::contentElement($html);
        $sourceText = self::sourceText($content);
        foreach ($content->childNodes as $child) {
            $this->node($child, false);
        }
        $this->dropRunningHeaders();

        $declaration = $this->declaration === [] ? null : implode("\n", $this->declaration);

        // The final Employee Declaration is nothing but its sign-off panel. A
        // policy with no wording cannot be published, and a new starter should
        // read what they are about to agree to, so the statements are shown as
        // the body as well.
        if ($this->blocks === [] && $declaration !== null) {
            if ($this->declarationTitle !== null) {
                $this->blocks[] = ['type' => 'heading', 'level' => 2, 'text' => $this->declarationTitle];
            }
            foreach ($this->declaration as $line) {
                $this->blocks[] = ['type' => 'paragraph', 'text' => $line];
            }
            $this->declarationShownAsBody = true;
        }

        $report = [
            'blockCounts' => $this->blockCounts(),
            'wordCoverage' => self::wordCoverage($sourceText, $this->outputText($declaration)),
            'demotedH4' => $this->demotedH4,
            'flattenedNestedLists' => $this->flattenedNestedLists,
            'joinedListParagraphs' => $this->joinedListParagraphs,
            'lineBreaksJoined' => $this->lineBreaksJoined,
            'droppedUnderline' => $this->droppedUnderline,
            'droppedLinks' => $this->droppedLinks,
            'droppedHeadingEmphasis' => $this->droppedHeadingEmphasis,
            'droppedRunningHeaders' => $this->droppedRunningHeaders,
            'comparisonTables' => $this->comparisonTables,
            'declarationTitle' => $this->declarationTitle,
            'declarationShownAsBody' => $this->declarationShownAsBody,
            'signOffFields' => $this->signOffFields,
            'formItems' => $this->formItems,
            'declarationNeedsReview' => $this->declarationNeedsReview,
            'unknownElements' => $this->unknownElements,
        ];

        return ['blocks' => $this->blocks, 'declaration' => $declaration, 'report' => $report];
    }

    /**
     * @return array<string, int>
     */
    private function blockCounts(): array
    {
        $counts = [];
        foreach ($this->blocks as $block) {
            $type = is_string($block['type'] ?? null) ? $block['type'] : '(none)';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    private static function contentElement(string $html): DOMElement
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The encoding hint: without it libxml reads the file as Latin-1 and
        // every curly quote in the handbook becomes three characters.
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOBLANKS | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = (new DOMXPath($document))->query('//div[contains(concat(" ", normalize-space(@class), " "), " policy-content ")]');
        $element = $found === false ? null : $found->item(0);
        if (!$element instanceof DOMElement) {
            throw new RuntimeException('No <div class="policy-content"> in the section.');
        }

        return $element;
    }

    private function node(DOMNode $node, bool $inAside): void
    {
        if ($node instanceof DOMText) {
            $text = self::normaliseSpace($node->textContent);
            if ($text !== '') {
                $this->paragraph(['text' => $text, 'marks' => []], $inAside);
            }

            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $this->heading($node, $tag, $inAside);
                break;
            case 'p':
                if ($inAside && str_contains(' ' . $node->getAttribute('class') . ' ', ' source-fields ')) {
                    // "Name · Signature · Date" — the source's own label for the fields.
                    $this->signOffFields = [...$this->signOffFields, ...self::splitFields($this->inline($node)['text'])];
                    break;
                }
                $this->paragraph($this->inline($node), $inAside);
                break;
            case 'ul':
            case 'ol':
                $this->list($node, $inAside);
                break;
            case 'table':
                $this->table($node, $inAside);
                break;
            case 'aside':
                foreach ($node->childNodes as $child) {
                    $this->node($child, true);
                }
                break;
            case 'div':
            case 'section':
                foreach ($node->childNodes as $child) {
                    $this->node($child, $inAside);
                }
                break;
            default:
                $this->unknownElements[] = $tag;
                $this->paragraph($this->inline($node), $inAside);
        }
    }

    private function heading(DOMElement $node, string $tag, bool $inAside): void
    {
        $inline = $this->inline($node);
        if ($inline['text'] === '') {
            return;
        }
        if ($inline['marks'] !== []) {
            $this->droppedHeadingEmphasis++;
        }

        if ($inAside) {
            if (preg_match(self::TICK_GROUP, $inline['text']) === 1) {
                $this->inTickGroup = true;
                $this->formItems[] = $inline['text'];
            } elseif (self::isFormItem($inline['text'])) {
                $this->formItems[] = $inline['text'];
            } elseif ($this->declarationTitle === null) {
                $this->declarationTitle = $inline['text'];
            } else {
                $this->declarationNeedsReview[] = 'Second heading in the sign-off sheet: ' . $inline['text'];
            }

            return;
        }

        if ($tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
            $this->demotedH4[] = $inline['text'];
        }
        $this->blocks[] = [
            'type' => 'heading',
            'level' => $tag === 'h1' || $tag === 'h2' ? 2 : 3,
            'text' => $inline['text'],
        ];
    }

    /**
     * @param array{text: string, marks: list<array{start: int, end: int, mark: string}>} $inline
     */
    private function paragraph(array $inline, bool $inAside): void
    {
        if ($inline['text'] === '') {
            return;
        }
        if ($inAside) {
            $this->declarationLine($inline['text']);

            return;
        }

        $block = ['type' => 'paragraph', 'text' => $inline['text']];
        if ($inline['marks'] !== []) {
            $block['marks'] = ['text' => $inline['marks']];
        }
        $this->blocks[] = $block;
    }

    private function declarationLine(string $text, string $prefix = ''): void
    {
        if (preg_match(self::FIELD_LABEL, $text) === 1) {
            $this->signOffFields[] = rtrim($text, ' :');

            return;
        }
        if ($this->inTickGroup || self::isFormItem($text)) {
            $this->formItems[] = $text;

            return;
        }
        // "As an Executive / Employee (please check) of TriCab, I acknowledge …":
        // the opening clause is the instruction for a role tick box, which the
        // form asks. The declaration starts at what is being acknowledged.
        if (preg_match('/^(As an? [^,]*\(please check\)[^,]*),\s*(.+)$/iu', $text, $match) === 1) {
            $this->formItems[] = $match[1];
            $this->declarationNeedsReview[] = 'Role choice "' . $match[1] . '" moved to the form; the declaration starts "'
                . mb_substr($match[2], 0, 40) . '…".';
            $text = mb_strtoupper(mb_substr($match[2], 0, 1)) . mb_substr($match[2], 1);
        } elseif (preg_match('/\(please (check|indicate|tick)/iu', $text) === 1) {
            $this->declarationNeedsReview[] = 'Asks the reader to choose: ' . $text;
        }
        $this->declaration[] = $prefix . $text;
    }

    private function list(DOMElement $node, bool $inAside): void
    {
        $items = [];
        $marks = [];
        $this->listItems($node, $items, $marks, 0);
        if ($items === []) {
            return;
        }

        if ($inAside) {
            foreach ($items as $item) {
                $this->declarationLine($item, '• ');
            }

            return;
        }

        $style = 'bullet';
        if (strtolower($node->tagName) === 'ol') {
            $style = match ($node->getAttribute('type')) {
                'a' => 'lower-alpha',
                'A' => 'upper-alpha',
                'i' => 'lower-roman',
                'I' => 'upper-roman',
                default => 'number',
            };
        }
        $start = (int) ($node->getAttribute('start') ?: 1);

        $block = ['type' => 'list', 'style' => $style, 'start' => max(1, $start), 'items' => $items];
        if ($marks !== []) {
            $block['marks'] = $marks;
        }
        $this->blocks[] = $block;
    }

    /**
     * Nested lists are flattened into the parent: the block model has one
     * level. A nested item is marked with a dash so it still reads as
     * subordinate, and every one is reported.
     *
     * @param list<string> $items
     * @param array<string, list<array{start: int, end: int, mark: string}>> $marks
     */
    private function listItems(DOMElement $list, array &$items, array &$marks, int $depth): void
    {
        foreach ($list->childNodes as $li) {
            if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }

            $nested = [];
            $parts = [];
            foreach ($li->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested[] = $child;
                } else {
                    $parts[] = $child;
                }
            }

            $paragraphs = count(array_filter($parts, static fn (DOMNode $part): bool => $part instanceof DOMElement && strtolower($part->tagName) === 'p'));
            if ($paragraphs > 1) {
                $this->joinedListParagraphs++;
            }
            $inline = $this->inlineNodes($parts);
            if ($inline['text'] !== '') {
                $prefix = $depth > 0 ? '– ' : '';
                $index = count($items);
                $items[] = $prefix . $inline['text'];
                if ($inline['marks'] !== []) {
                    $marks['items.' . $index] = self::shift($inline['marks'], mb_strlen($prefix));
                }
                if ($depth > 0) {
                    $this->flattenedNestedLists[] = $inline['text'];
                }
            }

            foreach ($nested as $child) {
                $this->listItems($child, $items, $marks, $depth + 1);
            }
        }
    }

    private function table(DOMElement $node, bool $inAside): void
    {
        $rows = [];
        $headerRow = null;
        foreach ((new DOMXPath($node->ownerDocument ?? new DOMDocument()))->query('.//tr', $node) ?: [] as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }
            $cells = [];
            $isHeader = true;
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $isHeader = $isHeader && strtolower($cell->tagName) === 'th';
                    $cells[] = $this->inline($cell);
                }
            }
            if ($isHeader && $headerRow === null && $rows === []) {
                $headerRow = $cells;
            } else {
                $rows[] = $cells;
            }
        }
        $headerRow ??= array_shift($rows) ?? [];

        $headers = array_map(static fn (array $cell): string => $cell['text'], $headerRow);
        $bodyIsBlank = array_reduce(
            $rows,
            static fn (bool $blank, array $row): bool => $blank && implode('', array_column($row, 'text')) === '',
            true,
        );

        if ($inAside) {
            if ($bodyIsBlank) {
                // A sign-off grid: the headers are the fields.
                $this->signOffFields = [...$this->signOffFields, ...array_values(array_filter($headers))];
            } else {
                $this->declarationNeedsReview[] = 'A table with content inside the sign-off sheet was left out.';
            }

            return;
        }

        $named = array_values(array_filter($headers, static fn (string $header): bool => $header !== ''));
        if (array_map('strtolower', $named) === ['always', 'never']) {
            $this->comparisonTable($rows);

            return;
        }

        $block = ['type' => 'table', 'caption' => null, 'headers' => $headers, 'rows' => []];
        $marks = [];
        foreach ($rows as $r => $row) {
            $block['rows'][] = array_map(static fn (array $cell): string => $cell['text'], $row);
            foreach ($row as $c => $cell) {
                if ($cell['marks'] !== []) {
                    $marks['rows.' . $r . '.' . $c] = $cell['marks'];
                }
            }
        }
        if ($marks !== []) {
            $block['marks'] = $marks;
        }
        $this->blocks[] = $block;
    }

    /**
     * The Code of Conduct's "Always / Never" grids. In the PDF each side has a
     * bullet column of its own ("•" in a cell, or "•" and a line break before
     * the text). The bullets are dropped; the first cell with words is the
     * "always", the second the "never".
     *
     * @param list<list<array{text: string, marks: list<array{start: int, end: int, mark: string}>}>> $rows
     */
    private function comparisonTable(array $rows): void
    {
        $block = ['type' => 'comparisonTable', 'caption' => null, 'alwaysHeading' => 'Always', 'neverHeading' => 'Never', 'rows' => []];
        $marks = [];
        foreach ($rows as $row) {
            $sides = [];
            foreach ($row as $cell) {
                $stripped = self::stripBullet($cell);
                if ($stripped['text'] !== '') {
                    $sides[] = $stripped;
                }
            }
            if (count($sides) > 2) {
                throw new RuntimeException('An always/never row has more than two cells with words in it.');
            }
            $r = count($block['rows']);
            $entry = ['always' => [], 'never' => []];
            foreach (['always', 'never'] as $i => $side) {
                if (isset($sides[$i])) {
                    $entry[$side][] = $sides[$i]['text'];
                    if ($sides[$i]['marks'] !== []) {
                        $marks['rows.' . $r . '.' . $side . '.0'] = $sides[$i]['marks'];
                    }
                }
            }
            if ($entry['always'] !== [] || $entry['never'] !== []) {
                $block['rows'][] = $entry;
            }
        }
        if ($marks !== []) {
            $block['marks'] = $marks;
        }
        $this->comparisonTables++;
        $this->blocks[] = $block;
    }

    /**
     * A paragraph that is bold from end to end and appears more than once is
     * the PDF's running page header ("TriCab (Australia) Pty Ltd Leave
     * Policy"), and so is a heading with the same words. Both are dropped.
     */
    private function dropRunningHeaders(): void
    {
        $counts = [];
        foreach ($this->blocks as $block) {
            $text = self::wholeBoldText($block);
            if ($text !== null) {
                $counts[$text] = ($counts[$text] ?? 0) + 1;
            }
        }
        $running = array_map(strval(...), array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
        if ($running === []) {
            return;
        }

        $kept = [];
        foreach ($this->blocks as $block) {
            $text = $block['type'] === 'heading' && is_string($block['text'] ?? null) ? $block['text'] : self::wholeBoldText($block);
            if ($text !== null && in_array($text, $running, true)) {
                $this->droppedRunningHeaders[] = $text;
            } else {
                $kept[] = $block;
            }
        }
        $this->blocks = $kept;
    }

    /**
     * The paragraph's text when it is bold from the first character to the
     * last; otherwise null.
     *
     * @param array<string, mixed> $block
     */
    private static function wholeBoldText(array $block): ?string
    {
        $text = $block['text'] ?? null;
        $marks = $block['marks'] ?? null;
        if ($block['type'] !== 'paragraph' || !is_string($text) || !is_array($marks) || !is_array($marks['text'] ?? null)) {
            return null;
        }
        foreach ($marks['text'] as $range) {
            if (is_array($range) && $range === ['start' => 0, 'end' => mb_strlen($text), 'mark' => 'bold']) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return array{text: string, marks: list<array{start: int, end: int, mark: string}>}
     */
    private function inline(DOMNode $node): array
    {
        return $this->inlineNodes(iterator_to_array($node->childNodes));
    }

    /**
     * Flattens inline content to text plus bold/italic ranges. Whitespace is
     * collapsed across element boundaries, the way a browser would show it.
     *
     * @param iterable<DOMNode> $nodes
     *
     * @return array{text: string, marks: list<array{start: int, end: int, mark: string}>}
     */
    private function inlineNodes(iterable $nodes): array
    {
        /** @var list<array{0: string, 1: bool, 2: bool}> $chars */
        $chars = [];
        foreach ($nodes as $node) {
            $this->collect($node, false, false, $chars);
            // Block-level children (a <p> inside an <li>) meet at a space.
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'p') {
                $chars[] = [' ', false, false];
            }
        }

        // Collapse whitespace and trim.
        $collapsed = [];
        foreach ($chars as $char) {
            $isSpace = preg_match('/^\s$/u', $char[0]) === 1 || $char[0] === "\u{00A0}";
            if ($isSpace) {
                if ($collapsed === [] || $collapsed[count($collapsed) - 1][0] === ' ') {
                    continue;
                }
                $collapsed[] = [' ', $char[1], $char[2]];
            } else {
                $collapsed[] = $char;
            }
        }
        while ($collapsed !== [] && $collapsed[count($collapsed) - 1][0] === ' ') {
            array_pop($collapsed);
        }

        // A space between two bold characters is bold, so "<b>A</b> <b>B</b>"
        // is one range, not two.
        $count = count($collapsed);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($collapsed[$i][0] === ' ') {
                $collapsed[$i][1] = $collapsed[$i - 1][1] && $collapsed[$i + 1][1];
                $collapsed[$i][2] = $collapsed[$i - 1][2] && $collapsed[$i + 1][2];
            }
        }

        $text = implode('', array_column($collapsed, 0));
        $marks = [...self::ranges($collapsed, 1, 'bold'), ...self::ranges($collapsed, 2, 'italic')];
        usort($marks, static fn (array $a, array $b): int => [$a['start'], $a['mark']] <=> [$b['start'], $b['mark']]);

        return ['text' => $text, 'marks' => $marks];
    }

    /**
     * @param list<array{0: string, 1: bool, 2: bool}> $chars
     */
    private function collect(DOMNode $node, bool $bold, bool $italic, array &$chars): void
    {
        if ($node instanceof DOMText) {
            foreach (mb_str_split($node->textContent) as $char) {
                $chars[] = [$char, $bold, $italic];
            }

            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            // The PDF's line wraps. A space, not a new paragraph.
            $this->lineBreaksJoined++;
            $chars[] = [' ', $bold, $italic];

            return;
        }
        if ($tag === 'u') {
            $this->droppedUnderline[] = self::normaliseSpace($node->textContent);
        }
        if ($tag === 'a') {
            $this->droppedLinks[] = ['text' => self::normaliseSpace($node->textContent), 'href' => $node->getAttribute('href')];
        }

        $bold = $bold || $tag === 'strong' || $tag === 'b';
        $italic = $italic || $tag === 'em' || $tag === 'i';
        foreach ($node->childNodes as $child) {
            $this->collect($child, $bold, $italic, $chars);
        }
    }

    /**
     * @param list<array{0: string, 1: bool, 2: bool}> $chars
     *
     * @return list<array{start: int, end: int, mark: string}>
     */
    private static function ranges(array $chars, int $flag, string $mark): array
    {
        $ranges = [];
        $start = null;
        $count = count($chars);
        for ($i = 0; $i <= $count; $i++) {
            $on = $i < $count && $chars[$i][$flag];
            if ($on && $start === null) {
                $start = $i;
            } elseif (!$on && $start !== null) {
                $end = $i;
                // No range starts or ends on a space.
                while ($start < $end && $chars[$start][0] === ' ') {
                    $start++;
                }
                while ($end > $start && $chars[$end - 1][0] === ' ') {
                    $end--;
                }
                if ($end > $start) {
                    $ranges[] = ['start' => $start, 'end' => $end, 'mark' => $mark];
                }
                $start = null;
            }
        }

        return $ranges;
    }

    /**
     * @param list<array{start: int, end: int, mark: string}> $marks
     *
     * @return list<array{start: int, end: int, mark: string}>
     */
    private static function shift(array $marks, int $by): array
    {
        return array_map(static fn (array $range): array => ['start' => $range['start'] + $by, 'end' => $range['end'] + $by, 'mark' => $range['mark']], $marks);
    }

    /**
     * @param array{text: string, marks: list<array{start: int, end: int, mark: string}>} $cell
     *
     * @return array{text: string, marks: list<array{start: int, end: int, mark: string}>}
     */
    private static function stripBullet(array $cell): array
    {
        if (preg_match('/^•\s*/u', $cell['text'], $match) !== 1) {
            return $cell;
        }
        $cut = mb_strlen($match[0]);
        $marks = [];
        foreach ($cell['marks'] as $range) {
            $start = max(0, $range['start'] - $cut);
            $end = $range['end'] - $cut;
            if ($end > $start) {
                $marks[] = ['start' => $start, 'end' => $end, 'mark' => $range['mark']];
            }
        }

        return ['text' => mb_substr($cell['text'], $cut), 'marks' => $marks];
    }

    private static function isFormItem(string $text): bool
    {
        foreach (self::FORM_ITEM as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function splitFields(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s*·\s*/u', $text) ?: [])));
    }

    /** The section's words as a reader sees them: a `<br>` or a new cell separates words. */
    private static function sourceText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'br') {
            return ' ';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::sourceText($child);
        }
        $block = $node instanceof DOMElement
            && in_array(strtolower($node->tagName), ['p', 'li', 'td', 'th', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'aside', 'ul', 'ol', 'table'], true);

        return $block ? ' ' . $text . ' ' : $text;
    }

    private static function normaliseSpace(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * Everything the conversion kept or set aside on purpose, as one string, so
     * the word check can tell a word moved from a word lost.
     */
    private function outputText(?string $declaration): string
    {
        $parts = [$declaration ?? '', (string) $this->declarationTitle];
        array_walk_recursive($this->blocks, static function (mixed $value, int|string $key) use (&$parts): void {
            if (is_string($value) && !in_array($key, ['type', 'style', 'mark'], true)) {
                $parts[] = $value;
            }
        });
        $parts = [
            ...$parts,
            ...$this->droppedRunningHeaders,
            ...$this->signOffFields,
            ...$this->formItems,
        ];

        return implode(' ', $parts);
    }

    /**
     * @return array{sourceWords: int, missing: list<string>, coverage: float}
     */
    private static function wordCoverage(string $source, string $output): array
    {
        $words = static fn (string $text): array => array_count_values(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
        $in = $words($source);
        $out = $words($output);

        $missing = [];
        $total = 0;
        $lost = 0;
        foreach ($in as $word => $count) {
            $total += $count;
            $short = $count - ($out[$word] ?? 0);
            if ($short > 0) {
                $lost += $short;
                $missing[] = (string) $word;
            }
        }

        return [
            'sourceWords' => $total,
            'missing' => $missing,
            'coverage' => $total === 0 ? 1.0 : round(($total - $lost) / $total, 4),
        ];
    }
}
