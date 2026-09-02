<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantSearchPlan;

final class DeterministicAssistantQueryInterpreter implements AssistantQueryInterpreter
{
    private const INSTRUCTION_VERSION = 'product-assistant-query-rules-v1';

    /** @var list<string> */
    private const STOP_WORDS = [
        'a', 'about', 'any', 'are', 'available', 'can', 'could', 'do', 'does',
        'find', 'for', 'have', 'i', 'is', 'looking', 'me', 'need', 'of', 'please',
        'show', 'some', 'suitable', 'tell', 'that', 'the', 'these', 'this', 'to',
        'us', 'what', 'which', 'with', 'would', 'you', 'your',
    ];

    public function interpret(
        string $message,
        ?string $explicitSearchQuery = null,
        ?string $regionCode = null,
    ): AssistantSearchPlan {
        $explicit = trim((string) $explicitSearchQuery);
        if ($explicit !== '') {
            return $this->planFor($explicit, tryVerbatimFirst: true);
        }

        return $this->planFor(trim($message), tryVerbatimFirst: false);
    }

    /**
     * An explicit search query is what the customer typed into the search box, so it is tried
     * verbatim first — a pasted code or SKU must still win as an exact match. Everything after it
     * is the same graded degradation the message path already gets: the intent vocabulary when a
     * rule matches, otherwise the query with stop words stripped, then token by token.
     *
     * Returning the explicit string on its own was enough while search was one opaque LIKE. Phase 2
     * made matching AND-across-tokens, so a single unmatchable token now zeroes the whole result:
     * "I need LSZH marine cable, 4 cores, 4mm2" from the hero found nothing, because "i", "need"
     * and "4mm2" have to match too. It also skipped `knownIntent`, losing the LSZH rule entirely.
     */
    private function planFor(string $query, bool $tryVerbatimFirst): AssistantSearchPlan
    {
        $known = $this->knownIntent($query);
        if ($known !== null) {
            return new AssistantSearchPlan(
                originalQuery: $query,
                canonicalIntent: $known['intent'],
                searchTerms: $tryVerbatimFirst
                    ? $this->uniqueTerms(array_merge([$query], $known['terms']))
                    : $known['terms'],
                clarificationQuestion: $known['question'],
                instructionVersion: self::INSTRUCTION_VERSION,
            );
        }

        $terms = $this->fallbackTerms($query);
        if ($tryVerbatimFirst) {
            $terms = array_slice($this->uniqueTerms(array_merge([$query], $terms)), 0, 6);
        }

        return new AssistantSearchPlan(
            originalQuery: $query,
            canonicalIntent: null,
            searchTerms: $terms,
            clarificationQuestion: 'What application, voltage and cable construction do you need?',
            instructionVersion: self::INSTRUCTION_VERSION,
            // Left false on the explicit path. The graded terms above resolve it deterministically,
            // and Phase 4 retires this pre-search interpreter rather than widening its Foundry use.
            semanticInterpretationRecommended: !$tryVerbatimFirst && count($terms) > 1,
        );
    }

    /**
     * @return array{intent: string, terms: list<string>, question: string}|null
     */
    private function knownIntent(string $query): ?array
    {
        $rules = [
            [
                'pattern' => '/\b(?:fire[ -]?(?:rated|resistant|survival)?|radox|ws52w|x-?125|r-?125|hf-?110)\b/i',
                'intent' => 'Fire Resistant',
                'terms' => ['Fire Resistant', 'FR', 'Radox', 'WS52W', 'X-125', 'HF-110'],
                'question' => 'What voltage, conductor material, core count and construction do you need?',
            ],
            [
                'pattern' => '/\b(?:vsd|vfd|emc|variable\s+speed\s+drive|variable\s+frequency\s+drive)\b/i',
                'intent' => 'VSD/VFD',
                'terms' => ['VSD', 'VFD', 'EMC'],
                'question' => 'What voltage, conductor size, core arrangement and screening construction do you need?',
            ],
            [
                'pattern' => '/\b(?:lszh|ls0h|hffr|low\s+smoke|halogen[ -]free)\b/i',
                'intent' => 'Low Smoke Zero Halogen (LSZH)',
                'terms' => ['LSZH', 'HFFR', 'Low Smoke', 'Halogen Free'],
                'question' => 'What application, voltage, core count and conductor size do you need?',
            ],
            [
                'pattern' => '/\b(?:instrument|instrumentation)\b/i',
                'intent' => 'Instrumentation',
                'terms' => ['Instrumentation', 'Instrument'],
                'question' => 'How many pairs or triads, what conductor size, and what screening construction do you need?',
            ],
            [
                'pattern' => '/\bcontrol(?:s)?\b/i',
                'intent' => 'Control',
                'terms' => ['Control'],
                'question' => 'What voltage, core count, conductor size and screening construction do you need?',
            ],
            [
                'pattern' => '/\bsolar\b/i',
                'intent' => 'Solar',
                'terms' => ['Solar'],
                'question' => 'What voltage, conductor size and installation environment do you need?',
            ],
            [
                'pattern' => '/\bweld(?:ing)?\b/i',
                'intent' => 'Welding',
                'terms' => ['Welding', 'Weld'],
                'question' => 'What conductor size, flexibility and duty requirements do you need?',
            ],
            [
                'pattern' => '/\bbuilding\s+wire\b/i',
                'intent' => 'Building Wire',
                'terms' => ['Building Wire'],
                'question' => 'What voltage, conductor size, colour and installation method do you need?',
            ],
        ];

        foreach ($rules as $rule) {
            if (preg_match($rule['pattern'], $query) === 1) {
                return [
                    'intent' => $rule['intent'],
                    'terms' => $rule['terms'],
                    'question' => $rule['question'],
                ];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function fallbackTerms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}.\/+\-]*/u', $query, $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            $token = trim($token, '.,;:!?');
            if ($token === '') {
                continue;
            }
            $lower = mb_strtolower($token);
            if (in_array($lower, self::STOP_WORDS, true)) {
                continue;
            }
            $tokens[] = $lower === 'cables' ? 'cable' : $token;
        }

        $technical = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !in_array(mb_strtolower($token), ['cable', 'product', 'products'], true),
        ));
        if ($technical === []) {
            return ['cable'];
        }

        $terms = [implode(' ', $technical)];
        if (count($technical) > 1) {
            foreach ($technical as $token) {
                if (mb_strlen($token) >= 2) {
                    $terms[] = $token;
                }
            }
        }

        return array_slice($this->uniqueTerms($terms), 0, 6);
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function uniqueTerms(array $terms): array
    {
        $unique = [];
        $seen = [];
        foreach ($terms as $term) {
            $trimmed = trim($term);
            $key = mb_strtolower($trimmed);
            if ($trimmed === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $trimmed;
        }

        return $unique;
    }
}
