<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;

/**
 * Everything a tool needs to know about the turn, resolved by the server. The
 * model's input never reaches any of it. `revision` is the saved revision the
 * turn is working from: it starts as the one the user was looking at and moves
 * with each write the assistant makes.
 */
final class QuotegenTurnContext
{
    public function __construct(
        public readonly RfqActor $actor,
        public readonly string $actorEmail,
        public readonly string $rfqPublicId,
        public readonly string $conversationId,
        public readonly string $turnMessageId,
        public int $revision,
    ) {
    }

    public function mayViewPricing(): bool
    {
        return $this->actor->can(Module::PERMISSION_PRICING_VIEW);
    }
}
