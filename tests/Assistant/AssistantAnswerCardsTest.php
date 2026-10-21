<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Application\AssistantAnswerCards;

final class AssistantAnswerCardsTest extends TestCase
{
    public function test_research_cards_are_dropped_when_the_answer_recommends_something_else(): void
    {
        // The reported failure. Asked for a low-smoke style similar to KL, the
        // agent searched KL's 816 codes to describe KL, then recommended CC.
        // The customer saw four KL cards under a recommendation to use CC.
        $answer = <<<'TEXT'
        `KL` itself is a rubber-jacketed building/panel wire, not LSZH. The closest LSZH
        equivalent is `CC` — Flexible LSZH (Fixed Wiring).
        TEXT;

        $kept = AssistantAnswerCards::mentionedIn($answer, [
            self::codeCard('KL-CAXN/12C1.5BK'),
            self::codeCard('KL-CAXN/7C1.5BK'),
            self::codeCard('KL-PAXN/3C2.5OR'),
            self::codeCard('KL-PAXN/5C2.5OR'),
        ]);

        self::assertSame([], $kept, 'the answer names no code, so it offers no product');
    }

    public function test_a_code_the_answer_names_is_kept(): void
    {
        $kept = AssistantAnswerCards::mentionedIn(
            'Yes — this exists as `KL-PAXN/1C95OR`, a 1-core 95 mm² cable in orange.',
            [self::codeCard('KL-PAXN/1C95OR')],
        );

        self::assertCount(1, $kept);
        self::assertSame('KL-PAXN/1C95OR', $kept[0]['codeValue']);
    }

    public function test_only_the_named_code_survives_a_mixed_set(): void
    {
        // The ordinary agent turn: several codes inspected, one recommended.
        $kept = AssistantAnswerCards::mentionedIn(
            'Of the three, `KL-PAXN/3C2.5OR` is the one that matches your duty.',
            [
                self::codeCard('KL-PAXN/3C2.5OR'),
                self::codeCard('KL-PAXN/5C2.5OR'),
                self::codeCard('KL-CAXN/7C1.5BK'),
            ],
        );

        self::assertCount(1, $kept);
        self::assertSame('KL-PAXN/3C2.5OR', $kept[0]['codeValue']);
    }

    public function test_a_code_written_in_a_different_case_still_matches(): void
    {
        $kept = AssistantAnswerCards::mentionedIn(
            'Try kl-paxn/1c95or.',
            [self::codeCard('KL-PAXN/1C95OR')],
        );

        self::assertCount(1, $kept);
    }

    public function test_a_product_card_matches_on_title_or_eyebrow(): void
    {
        $byTitle = AssistantAnswerCards::mentionedIn(
            'The Marine Shipboard Power range covers that application.',
            [self::productCard('Marine Shipboard Power', 'BV Series')],
        );
        $byEyebrow = AssistantAnswerCards::mentionedIn(
            'That is the BV Series.',
            [self::productCard('Marine Shipboard Power', 'BV Series')],
        );

        self::assertCount(1, $byTitle);
        self::assertCount(1, $byEyebrow);
    }

    public function test_an_identifier_too_short_to_be_distinctive_never_matches(): void
    {
        // A two-character style code occurs inside ordinary words — "CC" is in
        // "according", "OR" in "orange". Matching those would put a product in
        // front of a customer the assistant never recommended, which is the
        // failure this class exists to prevent.
        $kept = AssistantAnswerCards::mentionedIn(
            'According to the datasheet, the orange sheath is standard.',
            [self::productCard('CC', 'OR')],
        );

        self::assertSame([], $kept);
    }

    public function test_a_style_named_as_a_bare_code_span_keeps_its_card(): void
    {
        $kept = AssistantAnswerCards::mentionedIn(
            'For trailing duty, `KA` and `CA` are the usual picks.',
            [self::styleCard('KA'), self::styleCard('CA'), self::styleCard('XA')],
        );

        self::assertSame(['KA', 'CA'], array_column($kept, 'styleCode'));
    }

    public function test_a_style_card_needs_the_code_span_itself(): void
    {
        // Two characters are matched only as a whole span, case-sensitively:
        // "according" and "Ka" are prose, and `KA-PAXN/4C16OR` is a code.
        $kept = AssistantAnswerCards::mentionedIn(
            'According to the register, Ka armour suits it — `KA-PAXN/4C16OR` for example, or `ka`.',
            [self::styleCard('KA'), self::styleCard('CC')],
        );

        self::assertSame([], $kept);
    }

    public function test_style_cards_follow_the_order_the_answer_names_them_and_stop_at_six(): void
    {
        $codes = ['V4', 'V3', 'V2', 'XA', 'KA', 'CA', 'BH'];
        $answer = implode(', ', array_map(static fn (string $code): string => '`' . $code . '`', $codes));

        $kept = AssistantAnswerCards::mentionedIn($answer, array_map(self::styleCard(...), array_reverse($codes)));

        self::assertSame(array_slice($codes, 0, AssistantAnswerCards::MAX_STYLE_CARDS), array_column($kept, 'styleCode'));
    }

    public function test_a_style_card_is_dropped_when_a_code_card_already_shows_that_style(): void
    {
        $answer = '**No**, `KA` is not LSZH. For LSZH use `CA`. Your code is `KA-PASN/4C95OR`.';
        $code = self::codeCard('KA-PASN/4C95OR');
        $code['segments'] = ['styleCode' => 'KA'];

        $kept = AssistantAnswerCards::mentionedIn($answer, [$code, self::styleCard('KA'), self::styleCard('CA')]);

        self::assertSame(['product-code', 'style'], array_column($kept, 'type'));
        self::assertSame('CA', $kept[1]['styleCode']);
    }

    public function test_a_code_card_without_segments_still_claims_its_style_by_prefix(): void
    {
        $kept = AssistantAnswerCards::mentionedIn(
            '`KL-PAXN/2C2.5OR` is the code; `KL` is not fire resistant, `FR` is.',
            [self::codeCard('KL-PAXN/2C2.5OR'), self::styleCard('KL'), self::styleCard('FR')],
        );

        self::assertSame(['KL-PAXN/2C2.5OR', 'FR'], array_map(
            static fn (array $card): mixed => $card['codeValue'] ?? $card['styleCode'],
            $kept,
        ));
    }

    public function test_the_last_size_picker_is_kept_whatever_the_text_says(): void
    {
        $kept = AssistantAnswerCards::mentionedIn('How many cores do you need?', [
            ['type' => 'size-picker', 'styleCode' => 'KA', 'coreCount' => null],
            self::codeCard('KL-PAXN/1C95OR'),
            ['type' => 'size-picker', 'styleCode' => 'KA', 'coreCount' => 4],
        ]);

        self::assertCount(1, $kept);
        self::assertSame('size-picker', $kept[0]['type']);
        self::assertSame(4, $kept[0]['coreCount']);
    }

    public function test_nothing_survives_an_empty_answer_or_an_empty_set(): void
    {
        self::assertSame([], AssistantAnswerCards::mentionedIn('', [self::codeCard('KL-PAXN/1C95OR')]));
        self::assertSame([], AssistantAnswerCards::mentionedIn('Anything at all.', []));
    }

    /** @return array<string, mixed> */
    private static function codeCard(string $codeValue): array
    {
        return [
            'type' => 'product-code',
            'codeValue' => $codeValue,
            'title' => 'Flexible Rubber (Fixed Wiring)',
            'stockStatus' => 'STOCK',
        ];
    }

    /** @return array<string, mixed> */
    private static function styleCard(string $styleCode): array
    {
        return [
            'type' => 'style',
            'styleCode' => $styleCode,
            'title' => 'Flexible Rubber Armoured',
        ];
    }

    /** @return array<string, mixed> */
    private static function productCard(string $title, string $eyebrow): array
    {
        return [
            'type' => 'product',
            'publicId' => '0f8f1b6e-1c2d-4a3b-8c7d-9e0f1a2b3c4d',
            'title' => $title,
            'eyebrow' => $eyebrow,
        ];
    }
}
