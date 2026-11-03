<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

/** What one RFQ draft's assistant has spent and has set aside, against its limit. All in millionths of USD. */
final readonly class QuotegenBudget
{
    public function __construct(
        public int $limitMicros,
        public int $spentMicros,
        public int $reservedMicros,
    ) {
    }

    public function exhausted(): bool
    {
        return $this->spentMicros + $this->reservedMicros >= $this->limitMicros;
    }

    /** @return array{currency: string, limitMicros: int, spentMicros: int, reservedMicros: int, exhausted: bool} */
    public function toArray(): array
    {
        return [
            'currency' => 'USD',
            'limitMicros' => $this->limitMicros,
            'spentMicros' => $this->spentMicros,
            'reservedMicros' => $this->reservedMicros,
            'exhausted' => $this->exhausted(),
        ];
    }
}
