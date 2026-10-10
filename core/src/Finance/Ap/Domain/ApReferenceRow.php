<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/** One row of a Settings list: company, cost centre or account category. */
final readonly class ApReferenceRow
{
    /**
     * @param array<string, string> $values the list's text columns, by name (see {@see ApReferenceSpec::$fields})
     */
    public function __construct(
        public string $list,
        public string $id,
        public array $values,
        public bool $active,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A copy with some text columns and/or the flag replaced.
     *
     * @param array<string, string> $values
     */
    public function with(array $values = [], ?bool $active = null): self
    {
        return new self($this->list, $this->id, array_merge($this->values, $values), $active ?? $this->active, $this->createdAt);
    }
}
