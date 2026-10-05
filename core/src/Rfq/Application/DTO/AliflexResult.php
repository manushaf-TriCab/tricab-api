<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * What ALIFLEX came to (parity plan E7.4). Contract `rfq.aliflex-result.v1`.
 *
 * `publicId` is the new draft, or null when no line matched the rule and so no
 * draft was made (the source's answer; `message` says so). The source draft is
 * never touched. `counts` are the lines `changed`, left `unchanged` and held for
 * `review`. `pricingError` is set when the new draft's prices could not be had:
 * the draft exists, and its lines are priced when it is next saved or checked.
 */
final class AliflexResult
{
    /**
     * @param array{changed: int, unchanged: int, review: int} $counts
     */
    public function __construct(
        public readonly ?string $publicId,
        public readonly ?string $label,
        public readonly array $counts,
        public readonly string $message,
        public readonly ?string $pricingError,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'label' => $this->label,
            'revision' => $this->publicId === null ? null : 1,
            'counts' => $this->counts,
            'message' => $this->message,
            'pricingError' => $this->pricingError,
        ];
    }
}
