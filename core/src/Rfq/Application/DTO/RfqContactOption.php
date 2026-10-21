<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/** One of a customer's contacts: `code` is the 4js contact id the header carries. */
final class RfqContactOption
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        /** The contact's position (job title) in the book, when it has one. */
        public readonly ?string $position = null,
    ) {
    }

    /** @return array{code: string, name: string, email: ?string, phone: ?string, position: ?string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'position' => $this->position];
    }
}
