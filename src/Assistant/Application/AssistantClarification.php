<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * An assistant message split into the prose the customer reads and the closed
 * set of choices the web client renders as tappable buttons.
 */
final readonly class AssistantClarification
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $text,
        public array $options = [],
    ) {
    }
}
