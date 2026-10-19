<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * What a {@see \TriCab\Core\CableFit\Contract\ResearchModel} returns: the content blocks of the whole
 * exchange (every turn, when the model paused and was continued) in Anthropic Messages format, and the cost.
 * The runner reads the answer and the search results' `page_age` from the blocks.
 */
final readonly class ResearchModelResponse
{
    /** @param list<array<string, mixed>> $content */
    public function __construct(
        public array $content,
        public ResearchCost $cost,
    ) {
    }

    /**
     * From one raw Messages API response (a recorded one, in tests).
     *
     * @param array<string, mixed> $message
     */
    public static function fromMessage(array $message): self
    {
        $content = [];
        $raw = $message['content'] ?? null;
        foreach (is_array($raw) ? $raw : [] as $block) {
            if (is_array($block)) {
                /** @var array<string, mixed> $block */
                $content[] = $block;
            }
        }

        return new self($content, self::costOf($message));
    }

    /**
     * Tokens and searches of one Messages API response.
     *
     * @param array<string, mixed> $message
     */
    public static function costOf(array $message): ResearchCost
    {
        $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
        $server = is_array($usage['server_tool_use'] ?? null) ? $usage['server_tool_use'] : [];
        $searches = $server['web_search_requests'] ?? null;
        if (!is_int($searches)) {
            $searches = 0;
            $blocks = $message['content'] ?? null;
            foreach (is_array($blocks) ? $blocks : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'server_tool_use' && ($block['name'] ?? null) === 'web_search') {
                    $searches++;
                }
            }
        }
        $model = $message['model'] ?? null;
        $in = $usage['input_tokens'] ?? 0;
        $out = $usage['output_tokens'] ?? 0;

        return new ResearchCost(
            is_string($model) ? $model : '',
            is_int($in) ? $in : 0,
            is_int($out) ? $out : 0,
            $searches,
        );
    }
}
