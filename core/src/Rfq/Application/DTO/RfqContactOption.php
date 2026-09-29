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
    ) {
    }

    /** @return array{code: string, name: string, email: ?string, phone: ?string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone];
    }
}
