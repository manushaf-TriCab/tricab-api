<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/** A 4js payment term (`descript` desc_type 33): `code` is what the quote header sends. */
final class RfqPayTerm
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {
    }

    /** @return array{code: string, name: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name];
    }
}
