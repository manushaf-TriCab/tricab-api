<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\PolicyBlockRules;
use TriCab\Core\Onboarding\Domain\Service\TrainingAuthoringRules;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * What an author may write (4.4). The policy cases port the source's own tests
 * from `test/api.test.mjs` — list styles and starts, comparison bullets, custom
 * table cells, a wrong-width row, raw HTML refused — and the question cases
 * port `parseModulePayload`'s rules. Synthetic content throughout.
 */
final class ContentAuthoringRulesTest extends TestCase
{
    // ------------------------------------------------------------ policies

    public function test_every_list_style_is_kept_with_its_start(): void
    {
        foreach (PolicyBlockRules::LIST_STYLES as $style) {
            $blocks = PolicyBlockRules::normalise([['type' => 'list', 'style' => $style, 'start' => 4, 'items' => ['One', 'Two']]]);
            self::assertSame(['type' => 'list', 'style' => $style, 'start' => 4, 'items' => ['One', 'Two']], $blocks[0]);
        }
    }

    public function test_a_list_defaults_to_bullets_from_one_and_drops_blank_lines(): void
    {
        $blocks = PolicyBlockRules::normalise([['type' => 'list', 'items' => ['Wear PPE', '  ', 'Report hazards', '']]]);

        self::assertSame(['type' => 'list', 'style' => 'bullet', 'start' => 1, 'items' => ['Wear PPE', 'Report hazards']], $blocks[0]);
    }

    public function test_comparison_rows_keep_their_bullets_and_default_headings(): void
    {
        $blocks = PolicyBlockRules::normalise([[
            'type' => 'comparisonTable',
            'rows' => [['always' => ['Be on time', 'Say hello'], 'never' => ['Shout']], ['never' => ['Run on the floor']]],
        ]]);

        self::assertSame('Always', $blocks[0]['alwaysHeading']);
        self::assertSame('Never', $blocks[0]['neverHeading']);
        self::assertSame([['always' => ['Be on time', 'Say hello'], 'never' => ['Shout']], ['always' => [], 'never' => ['Run on the floor']]], $blocks[0]['rows']);
    }

    public function test_table_cells_keep_their_position_including_empty_ones(): void
    {
        $blocks = PolicyBlockRules::normalise([[
            'type' => 'table',
            'caption' => 'Leave types',
            'headers' => ['Type', 'Days', 'Notes'],
            'rows' => [['Annual', '20', ''], ['Personal', '10', 'Includes carer’s leave']],
        ]]);

        self::assertSame([['Annual', '20', ''], ['Personal', '10', 'Includes carer’s leave']], $blocks[0]['rows']);
    }

    public function test_a_row_of_the_wrong_width_is_refused_by_number(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage('policy block 2 (table) row 1');

        PolicyBlockRules::normalise([
            ['type' => 'paragraph', 'text' => 'Intro'],
            ['type' => 'table', 'headers' => ['A', 'B'], 'rows' => [['only one']]],
        ]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function refusedBlocks(): iterable
    {
        yield 'raw html' => [['type' => 'html', 'html' => '<script>alert(1)</script>']];
        yield 'unknown type' => [['type' => 'video', 'url' => 'https://example.test']];
        yield 'no type' => [['text' => 'Orphan']];
        yield 'empty paragraph' => [['type' => 'paragraph', 'text' => '   ']];
        yield 'heading level 1' => [['type' => 'heading', 'level' => 1, 'text' => 'Title']];
        yield 'unknown list style' => [['type' => 'list', 'style' => 'emoji', 'items' => ['x']]];
        yield 'list start out of range' => [['type' => 'list', 'start' => 101, 'items' => ['x']]];
        yield 'empty list' => [['type' => 'list', 'items' => []]];
        yield 'callout tone' => [['type' => 'callout', 'tone' => 'danger', 'text' => 'x']];
        yield 'table too wide' => [['type' => 'table', 'headers' => ['1', '2', '3', '4', '5', '6', '7'], 'rows' => [['1', '2', '3', '4', '5', '6', '7']]]];
        yield 'empty comparison row' => [['type' => 'comparisonTable', 'rows' => [['always' => [], 'never' => []]]]];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[DataProvider('refusedBlocks')]
    public function test_a_block_outside_the_rules_is_refused(array $block): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        PolicyBlockRules::normalise([$block]);
    }

    public function test_html_in_text_is_kept_as_text_not_refused(): void
    {
        // The renderer emits text nodes, so markup in a paragraph is shown as
        // the characters it is. Refusing it would stop somebody writing "<5 kg".
        $blocks = PolicyBlockRules::normalise([['type' => 'paragraph', 'text' => 'Lift <5 kg alone. <b>not bold</b>']]);

        self::assertSame('Lift <5 kg alone. <b>not bold</b>', $blocks[0]['text']);
    }

    // ------------------------------------------------------------ emphasis

    public function test_marks_are_kept_beside_the_text_sorted_and_merged(): void
    {
        $blocks = PolicyBlockRules::normalise([[
            'type' => 'paragraph',
            'text' => 'Annual leave: four weeks.',
            'marks' => ['text' => [
                ['start' => 14, 'end' => 18, 'mark' => 'italic'],
                ['start' => 0, 'end' => 6, 'mark' => 'bold'],
                ['start' => 4, 'end' => 13, 'mark' => 'bold'],
            ]],
        ]]);

        self::assertSame([
            'type' => 'paragraph',
            'text' => 'Annual leave: four weeks.',
            'marks' => ['text' => [
                ['start' => 0, 'end' => 13, 'mark' => 'bold'],
                ['start' => 14, 'end' => 18, 'mark' => 'italic'],
            ]],
        ], $blocks[0]);
    }

    public function test_marks_move_with_trimmed_text_and_dropped_blank_lines(): void
    {
        $blocks = PolicyBlockRules::normalise([
            ['type' => 'paragraph', 'text' => '  Hello there  ', 'marks' => ['text' => [['start' => 2, 'end' => 7, 'mark' => 'bold'], ['start' => 13, 'end' => 15, 'mark' => 'italic']]]],
            ['type' => 'list', 'items' => ['', 'First', ' ', ' Second'], 'marks' => ['items.3' => [['start' => 1, 'end' => 7, 'mark' => 'bold']]]],
            ['type' => 'comparisonTable', 'rows' => [['always' => ['', 'Be kind'], 'never' => ['Shout']]], 'marks' => ['rows.0.always.1' => [['start' => 3, 'end' => 7, 'mark' => 'italic']]]],
            ['type' => 'table', 'headers' => ['A', 'B'], 'rows' => [['x', ' Yes']], 'marks' => ['rows.0.1' => [['start' => 1, 'end' => 4, 'mark' => 'bold']]]],
        ]);

        // The trailing italic covered only spaces, so it went with them.
        self::assertSame(['text' => [['start' => 0, 'end' => 5, 'mark' => 'bold']]], $blocks[0]['marks']);
        self::assertSame(['items.1' => [['start' => 0, 'end' => 6, 'mark' => 'bold']]], $blocks[1]['marks']);
        self::assertSame(['rows.0.always.0' => [['start' => 3, 'end' => 7, 'mark' => 'italic']]], $blocks[2]['marks']);
        self::assertSame(['rows.0.1' => [['start' => 0, 'end' => 3, 'mark' => 'bold']]], $blocks[3]['marks']);
    }

    public function test_a_block_with_no_marks_has_no_marks_key(): void
    {
        $blocks = PolicyBlockRules::normalise([['type' => 'paragraph', 'text' => 'Plain', 'marks' => []]]);

        self::assertSame(['type' => 'paragraph', 'text' => 'Plain'], $blocks[0]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function refusedMarks(): iterable
    {
        $paragraph = ['type' => 'paragraph', 'text' => 'Some words'];
        yield 'a heading' => [['type' => 'heading', 'text' => 'Title', 'marks' => ['text' => [['start' => 0, 'end' => 2, 'mark' => 'bold']]]]];
        yield 'underline' => [$paragraph + ['marks' => ['text' => [['start' => 0, 'end' => 2, 'mark' => 'underline']]]]];
        yield 'a link' => [$paragraph + ['marks' => ['text' => [['start' => 0, 'end' => 2, 'mark' => 'link', 'href' => 'https://example.test']]]]];
        yield 'end before start' => [$paragraph + ['marks' => ['text' => [['start' => 4, 'end' => 2, 'mark' => 'bold']]]]];
        yield 'not a number' => [$paragraph + ['marks' => ['text' => [['start' => '0', 'end' => 2, 'mark' => 'bold']]]]];
        yield 'unknown field' => [$paragraph + ['marks' => ['title' => [['start' => 0, 'end' => 2, 'mark' => 'bold']]]]];
        yield 'a list of marks' => [$paragraph + ['marks' => [['start' => 0, 'end' => 2, 'mark' => 'bold']]]];
        yield 'a missing item' => [['type' => 'list', 'items' => ['One'], 'marks' => ['items.4' => [['start' => 0, 'end' => 2, 'mark' => 'bold']]]]];
        yield 'a table header' => [['type' => 'table', 'headers' => ['A'], 'rows' => [['x']], 'marks' => ['headers.0' => [['start' => 0, 'end' => 1, 'mark' => 'bold']]]]];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[DataProvider('refusedMarks')]
    public function test_marks_outside_the_rules_are_refused(array $block): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        PolicyBlockRules::normalise([$block]);
    }

    public function test_too_many_blocks_are_refused(): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        PolicyBlockRules::normalise(array_fill(0, PolicyBlockRules::MAX_BLOCKS + 1, ['type' => 'paragraph', 'text' => 'x']));
    }

    public function test_an_empty_declaration_means_the_default(): void
    {
        self::assertNull(PolicyBlockRules::declaration('   '));
        self::assertSame('I agree.', PolicyBlockRules::declaration(' I agree. '));
    }

    // ------------------------------------------------------------ training

    public function test_a_well_formed_question_is_kept_with_its_answer_key(): void
    {
        $questions = TrainingAuthoringRules::questions([self::question()]);

        self::assertSame('b', $questions[0]['correctOptionKey']);
        self::assertSame([['key' => 'a', 'label' => 'Anywhere'], ['key' => 'b', 'label' => 'The walkways']], $questions[0]['options']);
        self::assertSame('Drivers expect people there.', $questions[0]['correctReason']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function refusedQuestions(): iterable
    {
        yield 'no wording' => [['prompt' => ' '] + self::question()];
        yield 'one option' => [['options' => [['key' => 'a', 'label' => 'Only']], 'correctOptionKey' => 'a'] + self::question()];
        yield 'seven options' => [['options' => array_map(static fn (int $i): array => ['key' => 'k' . $i, 'label' => 'L' . $i], range(1, 7)), 'correctOptionKey' => 'k1'] + self::question()];
        yield 'duplicate keys' => [['options' => [['key' => 'a', 'label' => 'One'], ['key' => 'a', 'label' => 'Two']], 'correctOptionKey' => 'a'] + self::question()];
        yield 'empty option' => [['options' => [['key' => 'a', 'label' => 'One'], ['key' => 'b', 'label' => '']], 'correctOptionKey' => 'a'] + self::question()];
        yield 'no correct answer' => [['correctOptionKey' => ''] + self::question()];
        yield 'correct answer names no option' => [['correctOptionKey' => 'z'] + self::question()];
        yield 'key with spaces' => [['options' => [['key' => 'a b', 'label' => 'One'], ['key' => 'c', 'label' => 'Two']], 'correctOptionKey' => 'c'] + self::question()];
    }

    /**
     * @param array<string, mixed> $question
     */
    #[DataProvider('refusedQuestions')]
    public function test_a_question_outside_the_rules_is_refused(array $question): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        TrainingAuthoringRules::questions([$question]);
    }

    public function test_slides_keep_their_ids_and_refuse_duplicates_and_bad_ids(): void
    {
        self::assertSame(
            [['id' => 'welcome', 'title' => 'Welcome'], ['id' => 'hazards-2', 'title' => 'Hazards']],
            TrainingAuthoringRules::slides([['id' => 'welcome', 'title' => 'Welcome'], ['id' => 'hazards-2', 'title' => ' Hazards ']]),
        );

        foreach ([
            [['id' => 'a', 'title' => 'A'], ['id' => 'a', 'title' => 'Again']],
            [['id' => 'Has Spaces', 'title' => 'X']],
            [['id' => 'ok', 'title' => '']],
            ['bare-string'],
        ] as $slides) {
            try {
                TrainingAuthoringRules::slides($slides);
                self::fail('Accepted ' . json_encode($slides, JSON_THROW_ON_ERROR));
            } catch (InvalidOnboardingValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_slide_keeps_its_image_media_and_links_and_drops_empty_material(): void
    {
        $slide = [
            'id' => 'hazards',
            'title' => 'Hazards',
            'imageUrl' => ' https://tricabv2.blob.core.windows.net/training-decks/d/v1/slides/slide-001.webp ',
            'altText' => '',
            'transcript' => null,
            'media' => [['type' => 'video', 'url' => 'https://example.test/v.mp4', 'mimeType' => 'Video/MP4', 'transcript' => '']],
            'links' => [['text' => ' Quality Policy ', 'url' => 'https://example.test/policy.pdf?e=1']],
            'requiresAnimationReview' => true,
        ];

        self::assertSame([[
            'id' => 'hazards',
            'title' => 'Hazards',
            'imageUrl' => 'https://tricabv2.blob.core.windows.net/training-decks/d/v1/slides/slide-001.webp',
            'media' => [['type' => 'video', 'url' => 'https://example.test/v.mp4', 'mimeType' => 'video/mp4']],
            'links' => [['text' => 'Quality Policy', 'url' => 'https://example.test/policy.pdf?e=1']],
        ]], TrainingAuthoringRules::slides([$slide]));

        self::assertSame(
            [['id' => 'plain', 'title' => 'Plain']],
            TrainingAuthoringRules::slides([['id' => 'plain', 'title' => 'Plain', 'media' => [], 'links' => [], 'imageUrl' => '']]),
            'a slide without material reads back exactly as before',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedSlideMaterial(): iterable
    {
        yield 'javascript image' => [['imageUrl' => 'javascript:alert(1)']];
        yield 'data image' => [['imageUrl' => 'data:image/png;base64,AAAA']];
        yield 'http image' => [['imageUrl' => 'http://example.test/a.webp']];
        yield 'relative image' => [['imageUrl' => 'slides/slide-001.webp']];
        yield 'protocol-relative image' => [['imageUrl' => '//example.test/a.webp']];
        yield 'credentials in url' => [['imageUrl' => 'https://user:pw@example.test/a.webp']];
        yield 'quote in url' => [['imageUrl' => 'https://example.test/a".webp']];
        yield 'media not a list' => [['media' => ['type' => 'video']]];
        yield 'media of an unknown type' => [['media' => [['type' => 'iframe', 'url' => 'https://example.test/x']]]];
        yield 'media without an address' => [['media' => [['type' => 'video']]]];
        yield 'video with an audio type' => [['media' => [['type' => 'video', 'url' => 'https://example.test/x', 'mimeType' => 'audio/mpeg']]]];
        yield 'link without text' => [['links' => [['text' => ' ', 'url' => 'https://example.test/']]]];
        yield 'javascript link' => [['links' => [['text' => 'Go', 'url' => 'javascript:void(0)']]]];
        yield 'alt text not text' => [['altText' => ['x']]];
    }

    /**
     * @param array<string, mixed> $material
     */
    #[DataProvider('refusedSlideMaterial')]
    public function test_slide_material_outside_the_rules_is_refused(array $material): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        TrainingAuthoringRules::slides([['id' => 'one', 'title' => 'One'] + $material]);
    }

    public function test_the_pass_mark_is_a_whole_percentage(): void
    {
        self::assertSame(80, TrainingAuthoringRules::passMark(80));
        foreach ([0, 101, '80', 80.5, null] as $bad) {
            try {
                TrainingAuthoringRules::passMark($bad);
                self::fail('Accepted ' . var_export($bad, true));
            } catch (InvalidOnboardingValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ---------------------------------------------------------------- forms

    public function test_an_hr_form_records_every_field_as_ordinary(): void
    {
        $schema = FormSchemaRules::normalise([
            ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text', 'required' => true],
            ['path' => 'shirt', 'label' => 'Shirt', 'type' => 'select', 'options' => [['key' => 'm', 'label' => 'Medium']]],
        ], 'hr');

        self::assertSame(['preferredName' => DataClass::ORDINARY_HR, 'shirt' => DataClass::ORDINARY_HR], $schema['classifications']);
        self::assertSame([['key' => 'm', 'label' => 'Medium']], $schema['fields'][1]['options']);
    }

    public function test_an_hr_form_refuses_a_payroll_field(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->expectExceptionMessage('payroll or restricted form');

        FormSchemaRules::normalise([['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text', 'dataClass' => DataClass::PAYROLL]], 'hr');
    }

    public function test_a_payroll_form_needs_a_class_on_every_field(): void
    {
        $schema = FormSchemaRules::normalise([
            ['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text', 'dataClass' => DataClass::PAYROLL],
            ['path' => 'preferredName', 'label' => 'Name', 'type' => 'text', 'dataClass' => DataClass::ORDINARY_HR],
        ], 'payroll');
        self::assertSame(['bank.bsb' => DataClass::PAYROLL, 'preferredName' => DataClass::ORDINARY_HR], $schema['classifications']);

        $this->expectException(InvalidOnboardingValue::class);
        FormSchemaRules::normalise([['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text']], 'payroll');
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>}>
     */
    public static function refusedFields(): iterable
    {
        yield 'duplicate path' => [[['path' => 'a', 'label' => 'A', 'type' => 'text'], ['path' => 'a', 'label' => 'Again', 'type' => 'text']]];
        yield 'path inside another' => [[['path' => 'bank', 'label' => 'Bank', 'type' => 'text'], ['path' => 'bank.bsb', 'label' => 'BSB', 'type' => 'text']]];
        yield 'bad path' => [[['path' => '1st', 'label' => 'A', 'type' => 'text']]];
        yield 'unknown type' => [[['path' => 'a', 'label' => 'A', 'type' => 'file']]];
        yield 'no label' => [[['path' => 'a', 'label' => '', 'type' => 'text']]];
        yield 'select without choices' => [[['path' => 'a', 'label' => 'A', 'type' => 'select', 'options' => []]]];
        yield 'required not boolean' => [[['path' => 'a', 'label' => 'A', 'type' => 'text', 'required' => 'yes']]];
        yield 'content class on a field' => [[['path' => 'a', 'label' => 'A', 'type' => 'text', 'dataClass' => DataClass::CONTENT]]];
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    #[DataProvider('refusedFields')]
    public function test_a_field_outside_the_rules_is_refused(array $fields): void
    {
        $this->expectException(InvalidOnboardingValue::class);

        FormSchemaRules::normalise($fields, 'restricted_hr');
    }

    /**
     * @return array<string, mixed>
     */
    private static function question(): array
    {
        return [
            'prompt' => 'Where may you walk while a forklift is operating?',
            'options' => [['key' => 'a', 'label' => 'Anywhere'], ['key' => 'b', 'label' => 'The walkways']],
            'correctOptionKey' => 'b',
            'correctReason' => 'Drivers expect people there.',
        ];
    }
}
