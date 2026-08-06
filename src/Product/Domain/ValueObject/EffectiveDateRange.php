<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use DateTimeImmutable;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class EffectiveDateRange
{
    private function __construct(
        private readonly ?DateTimeImmutable $fromUtc,
        private readonly ?DateTimeImmutable $toUtc,
    ) {
    }

    public static function of(?DateTimeImmutable $fromUtc, ?DateTimeImmutable $toUtc): self
    {
        if ($fromUtc !== null && $toUtc !== null && $toUtc < $fromUtc) {
            throw InvalidProductValue::for(
                'EffectiveDateRange',
                sprintf('%s..%s', $fromUtc->format(DATE_ATOM), $toUtc->format(DATE_ATOM)),
            );
        }

        return new self($fromUtc, $toUtc);
    }

    public function fromUtc(): ?DateTimeImmutable
    {
        return $this->fromUtc;
    }

    public function toUtc(): ?DateTimeImmutable
    {
        return $this->toUtc;
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        if ($this->fromUtc !== null && $instant < $this->fromUtc) {
            return false;
        }

        if ($this->toUtc !== null && $instant > $this->toUtc) {
            return false;
        }

        return true;
    }
}
