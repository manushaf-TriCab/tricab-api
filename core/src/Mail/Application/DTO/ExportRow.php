<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/**
 * One assessed thread in the labelled-set export. It deliberately holds no subject, preview, name or
 * address: the sender is only the domain, so the set can be shared for the accuracy numbers.
 */
final readonly class ExportRow
{
    public function __construct(
        public string $threadId,
        public string $internetMessageId,
        public DateTimeImmutable $receivedAt,
        public string $senderDomain,
        public string $label,
        public float $confidence,
        public string $model,
        public string $version,
        public bool $skipped,
        public bool $injectionSuspected,
        public ?string $decidedLabel,
        public ?string $decisionKind,
        public ?DateTimeImmutable $decidedAt,
        public ?string $decidedBy,
    ) {
    }

    /** The part after the last `@`, lower-cased; empty when there is none. */
    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(trim(substr($address, $at + 1)));
    }
}
