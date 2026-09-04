<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantClarificationOptions;

final class AssistantClarificationOptionsTest extends TestCase
{
    public function test_lifts_a_trailing_choice_list_out_of_the_text(): void
    {
        $result = AssistantClarificationOptions::extract(
            "That construction is screened. Which screen type do you need?\n\n- R\n- 1\n- H\n- L",
        );

        self::assertSame(['R', '1', 'H', 'L'], $result->options);
        self::assertSame('That construction is screened. Which screen type do you need?', $result->text);
    }

    public function test_strips_markdown_from_choice_labels(): void
    {
        $result = AssistantClarificationOptions::extract(
            "Which conductor do you need?\n\n- **Copper**\n- `Aluminium`",
        );

        self::assertSame(['Copper', 'Aluminium'], $result->options);
    }

    public function test_leaves_a_list_in_the_prose_when_something_follows_it(): void
    {
        $text = "Do you want the datasheet?\n\n- KL-PAXN\n- XL-PAXN\n\nBoth are stocked in Melbourne.";

        $result = AssistantClarificationOptions::extract($text);

        self::assertSame([], $result->options);
        self::assertSame($text, $result->text);
    }

    public function test_ignores_a_list_that_answers_rather_than_asks(): void
    {
        $text = "Here are the two closest matches.\n\n- KL-PAXN/1C50OR\n- XL-PAXN/1C50OR";

        $result = AssistantClarificationOptions::extract($text);

        self::assertSame([], $result->options);
        self::assertSame($text, $result->text);
    }

    public function test_reads_choices_from_an_inline_question_without_editing_the_text(): void
    {
        $text = 'Do you need copper or aluminium?';

        $result = AssistantClarificationOptions::extract($text);

        self::assertSame(['copper', 'aluminium'], $result->options);
        self::assertSame($text, $result->text);
    }

    public function test_reads_choices_after_a_colon(): void
    {
        $result = AssistantClarificationOptions::extract(
            'The style is still ambiguous. Which insulation: XLPE, PVC or LSZH?',
        );

        self::assertSame(['XLPE', 'PVC', 'LSZH'], $result->options);
    }

    public function test_offers_nothing_when_the_message_asks_no_question(): void
    {
        $text = "The code is **KL-PAXN/1C50OR**.\n\n- Stocked in Melbourne\n- 0.6/1 kV";

        $result = AssistantClarificationOptions::extract($text);

        self::assertSame([], $result->options);
        self::assertSame($text, $result->text);
    }

    public function test_rejects_a_choice_list_that_is_really_prose(): void
    {
        $result = AssistantClarificationOptions::extract(
            "Which do you prefer?\n\n- A short one\n- "
            . 'An option whose label runs well past the forty-eight character button limit',
        );

        self::assertSame([], $result->options);
    }

    public function test_keeps_the_whole_message_when_only_a_list_is_present(): void
    {
        $text = "- copper?\n- aluminium?";

        $result = AssistantClarificationOptions::extract($text);

        self::assertSame([], $result->options);
        self::assertSame($text, $result->text);
    }

    public function test_caps_a_choice_list_at_six(): void
    {
        $result = AssistantClarificationOptions::extract(
            "Which size do you need?\n\n- 1.5\n- 2.5\n- 4\n- 6\n- 10\n- 16\n- 25",
        );

        self::assertSame([], $result->options);
    }
}
