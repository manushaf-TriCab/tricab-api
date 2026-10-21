<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Keeps the cards the answer actually talks about.
 *
 * An agent turn accumulates a card for every row any tool returned along the
 * way, and the loop in `ClaudeFoundryAgentProvider` never discards them as the
 * conversation moves on. That conflates two different things: what the agent
 * *looked at* while working, and what it is *offering* the customer.
 *
 * The failure that prompted this: asked "do you have a low smoke cable style
 * similar to KL?", the agent — correctly, and exactly as the prompt tells it
 * to — searched KL's codes to describe KL's profile, then answered that the
 * LSZH equivalent is `CC`. The customer got a recommendation for CC underneath
 * four cards for KL, the style they were being steered away from, taken as the
 * arbitrary first four of 816 matches.
 *
 * So a card has to earn its place by being named in the answer. The response
 * prompt already requires product codes to be written in inline code spans, so
 * they appear verbatim and a substring match finds them.
 *
 * A card for a product rather than a code is matched on its title or eyebrow
 * (the product code, falling back to the series name) — the same test applied
 * to the identifiers a customer would recognise.
 *
 * A style card is matched on its code written as a whole inline code span,
 * `` `KA` ``, case-sensitively. Style codes are two characters, far too short
 * for the substring test, but the prompt writes style codes in code spans too,
 * and a span with nothing else in it is distinctive: it cannot occur inside
 * "according", and `` `KA-PAXN/4C16OR` `` is a code, not the style. Style
 * cards follow the order the answer names them in, capped so a long list does
 * not bury the reply.
 *
 * A style card is dropped when the answer also carries a code card of that style: the code card
 * already shows it, and the style is usually the one being ruled out ("No, `KA` is not LSZH —
 * `KA-PASN/4C95OR` … for LSZH use `CA`" shows the code and `CA`, not `KA` twice).
 *
 * The size picker is not research: the agent called its tool to ask, so the
 * last one asked for is always kept.
 *
 * Deliberately not applied to the deterministic providers. Their text is
 * templated and does not reliably name what it found, and their cards are the
 * whole of the answer rather than a residue of research.
 */
final class AssistantAnswerCards
{
    public const MAX_STYLE_CARDS = 6;

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    public static function mentionedIn(string $text, array $cards): array
    {
        if ($text === '' || $cards === []) {
            return [];
        }

        $kept = [];
        $styles = [];
        $picker = null;
        foreach ($cards as $card) {
            $type = $card['type'] ?? null;
            if ($type === 'size-picker') {
                $picker = $card;
                continue;
            }
            if ($type === 'style') {
                $position = self::styleMention($text, $card);
                if ($position !== null) {
                    $styles[] = ['position' => $position, 'card' => $card];
                }
                continue;
            }
            foreach (self::identifiers($card) as $identifier) {
                if (stripos($text, $identifier) !== false) {
                    $kept[] = $card;
                    break;
                }
            }
        }

        // A code card already shows its style, so a style card for the same style only repeats it —
        // and it is usually the style being ruled out ("No, KA is not LSZH… use CA").
        $codedStyles = [];
        foreach ($kept as $card) {
            if (($card['type'] ?? null) === 'product-code') {
                $codedStyles[self::codeStyle($card)] = true;
            }
        }
        $styles = array_values(array_filter(
            $styles,
            static fn (array $style): bool => !is_string($style['card']['styleCode'] ?? null)
                || !isset($codedStyles[strtoupper(trim($style['card']['styleCode']))]),
        ));

        usort($styles, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach (array_slice($styles, 0, self::MAX_STYLE_CARDS) as $style) {
            $kept[] = $style['card'];
        }
        if ($picker !== null) {
            $kept[] = $picker;
        }

        return $kept;
    }

    /**
     * The style a code card belongs to: its style segment, else the code's prefix.
     *
     * @param array<string, mixed> $card
     */
    private static function codeStyle(array $card): string
    {
        $segments = is_array($card['segments'] ?? null) ? $card['segments'] : [];
        $style = is_string($segments['styleCode'] ?? null) ? trim($segments['styleCode']) : '';
        if ($style === '' && is_string($card['codeValue'] ?? null)) {
            $style = explode('-', trim($card['codeValue']), 2)[0];
        }

        return strtoupper($style);
    }

    /**
     * Where the answer first names this style as `` `CODE` ``, or null.
     *
     * @param array<string, mixed> $card
     */
    private static function styleMention(string $text, array $card): ?int
    {
        $code = is_string($card['styleCode'] ?? null) ? trim($card['styleCode']) : '';
        if ($code === '') {
            return null;
        }
        $position = strpos($text, '`' . $code . '`');

        return $position === false ? null : $position;
    }

    /**
     * The strings that count as the answer naming this card.
     *
     * Short identifiers are dropped rather than matched: a two-character style
     * code such as "CC" occurs inside ordinary words, and an eyebrow carrying a
     * series name rather than a product code can be a common noun. A false
     * positive here puts a product in front of a customer that the assistant
     * never recommended, which is the whole failure being fixed.
     *
     * @param array<string, mixed> $card
     * @return list<string>
     */
    private static function identifiers(array $card): array
    {
        $candidates = ($card['type'] ?? null) === 'product-code'
            ? [$card['codeValue'] ?? null]
            : [$card['title'] ?? null, $card['eyebrow'] ?? null];

        $identifiers = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if (mb_strlen($trimmed) < 3) {
                continue;
            }
            $identifiers[] = $trimmed;
        }

        return $identifiers;
    }
}
