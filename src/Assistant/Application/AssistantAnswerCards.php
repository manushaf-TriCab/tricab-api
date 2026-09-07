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
 * Deliberately not applied to the deterministic providers. Their text is
 * templated and does not reliably name what it found, and their cards are the
 * whole of the answer rather than a residue of research.
 */
final class AssistantAnswerCards
{
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
        foreach ($cards as $card) {
            foreach (self::identifiers($card) as $identifier) {
                if (stripos($text, $identifier) !== false) {
                    $kept[] = $card;
                    break;
                }
            }
        }

        return $kept;
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
