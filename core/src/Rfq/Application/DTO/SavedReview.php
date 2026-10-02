<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * `POST /api/v1/rfqs/{publicId}/review` — Save Review (parity plan E5.5): the
 * session it logged, how many lines it logged, and how many approved lines
 * taught the correction memory something. Contract `rfq.review.v1`.
 */
final class SavedReview
{
    public function __construct(
        public readonly string $sessionId,
        public readonly int $savedEntries,
        public readonly int $learnedCorrections,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'savedEntries' => $this->savedEntries,
            'learnedCorrections' => $this->learnedCorrections,
        ];
    }
}
