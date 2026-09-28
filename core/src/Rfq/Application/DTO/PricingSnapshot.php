<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Prices for one revision, as computed at one moment. Contract `rfq.pricing.v1`.
 *
 * Bound to a revision number: pricing runs on the saved revision, not on rows
 * a browser sends (plan 4.3; source quirk Q4). A snapshot whose revision is not
 * the RFQ's latest, or whose header pricing inputs have since changed, is
 * stale and may not feed a sync.
 *
 * Money is decimal strings in `currency`. Whether `cost` and `margin` are sent
 * to a caller without `RFQ.PRICING.VIEW` is owner question Q9: this contract
 * carries them, and {@see toArray()} takes the decision as a flag so the
 * answer is one call site, not a second DTO.
 */
final class PricingSnapshot
{
    /**
     * @param list<array{lineUid: string, unitCost: ?string, marginPercent: ?string, unitSell: ?string, lineTotal: ?string, priceSource: string, note: ?string}> $lines
     */
    public function __construct(
        public readonly int $revision,
        public readonly string $currency,
        public readonly string $regionCode,
        public readonly DateTimeImmutable $computedAtUtc,
        public readonly array $lines,
        public readonly ?string $total,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeCostAndMargin): array
    {
        return [
            'revision' => $this->revision,
            'currency' => $this->currency,
            'regionCode' => $this->regionCode,
            'computedAt' => $this->computedAtUtc->format(DateTimeInterface::ATOM),
            'total' => $this->total,
            'lines' => array_map(
                static function (array $line) use ($includeCostAndMargin): array {
                    if (!$includeCostAndMargin) {
                        unset($line['unitCost'], $line['marginPercent']);
                    }

                    return $line;
                },
                $this->lines,
            ),
        ];
    }

    public function isCurrentFor(int $latestRevision): bool
    {
        return $this->revision === $latestRevision;
    }
}
