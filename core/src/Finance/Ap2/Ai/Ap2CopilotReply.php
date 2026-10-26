<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/** What Copilot answers: text for the person, and proposals the web may offer. */
final readonly class Ap2CopilotReply
{
    /**
     * @param list<Ap2CopilotProposal> $proposals
     */
    public function __construct(
        public string $reply,
        public array $proposals = [],
    ) {
    }
}
