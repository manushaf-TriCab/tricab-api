<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Derives the tappable reply choices offered under an assistant message.
 *
 * This lives on the server so every provider — the agent, the deterministic
 * search fallback and anything added later — offers choices the same way, and
 * so the web client never has to reverse-engineer structure out of rendered
 * prose. The web client renders Markdown, so a list left in the text is shown
 * to the customer as a list; a list lifted into choices must be removed from
 * the text or the customer sees the same options twice.
 *
 * Two shapes are recognised, in order:
 *
 * 1. A bulleted list that is the last thing in the message, which
 *    `product-assistant-response-v4` asks the agent to produce for a closed
 *    set of choices. The list is lifted out and removed from the text.
 * 2. A trailing inline question — "Do you need copper or aluminium?" — which
 *    the deterministic providers produce. The text is left intact, because the
 *    question reads as a sentence and the choices only shortcut answering it.
 */
final class AssistantClarificationOptions
{
    private const MIN_OPTIONS = 2;
    private const MAX_LIST_OPTIONS = 6;
    private const MAX_INLINE_OPTIONS = 5;
    private const MAX_OPTION_LENGTH = 48;

    public static function extract(string $text): AssistantClarification
    {
        if (!str_contains($text, '?')) {
            return new AssistantClarification($text);
        }

        $fromList = self::fromTrailingList($text);
        if ($fromList !== null) {
            return $fromList;
        }

        return new AssistantClarification($text, self::fromInlineQuestion($text));
    }

    /**
     * A closed choice list is the final element of the message, so anything
     * after it — a caveat, a closing sentence — means the list is ordinary
     * content and must stay in the prose.
     */
    private static function fromTrailingList(string $text): ?AssistantClarification
    {
        $lines = preg_split('/\R/', $text);
        if ($lines === false) {
            return null;
        }

        $index = count($lines) - 1;
        while ($index >= 0 && trim($lines[$index]) === '') {
            $index--;
        }

        $options = [];
        while ($index >= 0 && preg_match('/^\s*(?:[-*\x{2022}]|\d+[.)])\s+(.+?)\s*$/u', $lines[$index], $matches) === 1) {
            array_unshift($options, $matches[1]);
            $index--;
        }

        $options = self::normalise($options);
        if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_LIST_OPTIONS) {
            return null;
        }

        $remaining = rtrim(implode("\n", array_slice($lines, 0, $index + 1)));
        // The question itself has to survive, otherwise the customer is shown
        // buttons with nothing explaining what they answer.
        if ($remaining === '' || !str_contains($remaining, '?')) {
            return null;
        }

        return new AssistantClarification($remaining, $options);
    }

    /**
     * @return list<string>
     */
    private static function fromInlineQuestion(string $text): array
    {
        $found = preg_match_all('/[^.!?\n]{3,160}\?/u', $text, $matches);
        if ($found === false || $found === 0) {
            return [];
        }

        $question = trim(rtrim((string) end($matches[0]), '?'));
        $colon = mb_strrpos($question, ':');
        if ($colon !== false) {
            $question = mb_substr($question, $colon + 1);
        }

        $question = preg_replace(
            '/^(?:do you need|would you prefer|would you like|should it be|is it|are you after)\s+/i',
            '',
            trim($question),
        ) ?? '';

        $parts = preg_split('/\s*,\s*|\s+or\s+/i', $question);
        if ($parts === false) {
            return [];
        }

        $options = self::normalise(array_map(
            static fn (string $part): string => preg_replace('/^(?:and|or)\s+/i', '', trim($part)) ?? $part,
            $parts,
        ));

        return count($options) >= self::MIN_OPTIONS && count($options) <= self::MAX_INLINE_OPTIONS
            ? $options
            : [];
    }

    /**
     * Choices become button labels, so the Markdown the renderer would have
     * styled has to be flattened rather than shown as punctuation.
     *
     * One unusable entry rejects the whole set rather than being dropped. A
     * partial set of buttons silently loses an answer the customer may need,
     * and prose that merely looks like a list is the likelier explanation.
     *
     * @param array<int, string> $options
     * @return list<string>
     */
    private static function normalise(array $options): array
    {
        $cleaned = [];
        foreach ($options as $option) {
            $option = preg_replace('/[*`_]+/', '', $option) ?? $option;
            $option = trim(rtrim(trim($option), '.?!'));
            if ($option === '' || mb_strlen($option) > self::MAX_OPTION_LENGTH) {
                return [];
            }
            $cleaned[] = $option;
        }

        return array_values(array_unique($cleaned));
    }
}
