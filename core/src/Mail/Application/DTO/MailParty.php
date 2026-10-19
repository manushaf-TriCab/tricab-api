<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailParty`: a person or address on a message, or the person who decided. */
final readonly class MailParty
{
    public function __construct(public string $name, public string $address)
    {
    }

    /** @return array{name: string, address: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'address' => $this->address];
    }
}
